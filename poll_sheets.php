<?php
require_once('include.php');

$client = getGoogleClient();

$service = new Google_Service_Drive($client);

$log = '';

/********STEP 1: Organize File Folder information**********/

$folders = array();
$folderNames = array();
$folderParents = array();

//Find ALL the folders this google drive user has access to
$optParams = array(
  'q' => "mimeType = 'application/vnd.google-apps.folder'"
);
$searchResults = $service->files->listFiles($optParams);

$getParams = array(
  'fields' => "name, parents"
);

if (count($searchResults->getFiles())) {
	foreach ($searchResults->getFiles() as $file) {
		//record folder names & basic parent information
		$fileId = $file->getId();
		$result = $service->files->get($fileId, $getParams);
		$resultName = $result->name;
		$resultParent = $result->parents[0];
		$folders[$resultName] = $fileId;
		$folderNames[$fileId] = $resultName;
		$folderParents[$fileId] = array(
			0 => $resultParent
		);
	}
	
	//loop over each folder and figure out what its parents are, if any
	foreach($folders as $folderId) {
		$parents = $folderParents[$folderId];
		$numParents = count($parents);
		$topParent = $parents[($numParents-1)];
		
		if($topParent != "" && $topParent != NULL) {
			$match = true;
			while ($match) {
				$match = false;
				foreach($folders as $otherFolderId) {
					if($folderId != $otherFolderId && $topParent != "" && $topParent != NULL) {
						if($otherFolderId == $topParent)
						{
							$otherParentZero = $folderParents[$otherFolderId][0];
							
							if($otherParentZero != "" && $otherParentZero != NULL)
							{
								$folderParents[$folderId][$numParents] = $otherParentZero;
								
								$topParent = $folderParents[$folderId][$numParents];
								$numParents = $numParents + 1;
								$match = true;
							}
						}
					}
				}
			}
		}
	}
}

//at this point:
// *  $folders       is an array indexing FOLDER NAMES to FILE IDs
// *  $folderNames   is an array indexing FILE IDs to FOLDER NAMES
// *  $folderParents is an array indexing FILE IDs to an ordered list of parents (as FILE IDs)
// *  getFolderPath() can now be called like this: $filePath = getFolderPath($fileId, $folderNames, $folderParents)

/********STEP 2: Find all the actual FILES we're watching for**********/

foreach($watchlist as $listfile) {
   // Find the existing version of the file
   
   $optParams = array(
     'q' => "name = '". $listfile['google_title'] ."'"
   );
   $searchResults = $service->files->listFiles($optParams);
   $url = null;
   $remoteContents = '';
   $localContents = '';
   
   $getParams = array(
     'fields' => "name, parents"
   );
   // Attempt to get the file
   if (count($searchResults->getFiles())) {
      foreach ($searchResults->getFiles() as $file) {
		 $fileId = $file->getId();
         $result = $service->files->get($fileId, $getParams);
		 $resultName = $result->name;
		 $resultParent = $result->parents[0];
		 
		 //make sure it's the right file by checking the intended vs fully resolved folder path:
		 $resultPath = getFolderPath($resultParent, $folderNames, $folderParents);
         $intendedPath = $listfile['google_path'];
		 if($resultPath == $intendedPath)
		 {
			// Successfully retrieved the file from Sheets. Check against local.
			$remoteContents = $service->files->export($file->getID(), 'text/csv', array('alt'=>'media'))->getBody();
			
			if($remoteContents != "" && $remoteContents != NULL)
			{
				//convert Google's CSV format to Firetongue's TSV via a local java program:
				$remoteContents = csv2tsv($remoteContents);
				
				//read the local version of the file in our git repository
				$localFilePath = $listfile['local_path'] . DIRECTORY_SEPARATOR . $listfile['local_file'];
				$local = @fopen(localFilePath, 'r');
				
				while(is_resource($local) && !feof($local)) {
					$localContents .= fread($local, 1024);
				}
				@fclose($local);
				
				//if they files do not match
				if (compareTSV($remoteContents,$localContents) == false) {
					
					echo("New version of ". $listfile['local_file'] ." found at ". date('Y m d H:i:s') .". Attempting to commit...\n");
					$log .= "New version of ". $listfile['local_file'] ." found at ". date('Y m d H:i:s') .". Attempting to commit...\n";
					
					// Update the local copy
					$local = @fopen($localFilePath, 'w');
					
					if(is_resource($local))
					{
						fwrite($local, $remoteContents);
						@fclose($local);
						
					
						$localPath = $listfile['local_path'];
					
						// Push it to Github
						$log .= runCommand('git add '. $listfile['local_file'], $localPath);
						$log .= runCommand('git commit -m "'. COMMIT_MESSAGE .'"', $localPath);
						$log .= runCommand('git '. PUSH_COMMAND, $localPath);
					}
					else
					{
						$log .= "ERROR: could not open local file " . $localFilePath . " for writing\n";
					}
				}
				else {
					echo($listfile['local_file'] . " has no changes...\n");
				}
			}
		}
      }
   }
}

if (LOG_ENABLE && !empty($log)) {
   $h = fopen("sheets.log", "a");
   fwrite($h, $log ."\n");
}