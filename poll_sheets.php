<?php
require_once('include.php');

/********STEP 0: Initialize basic stuff**********/

$client = getGoogleClient("");

$service = new Google_Service_Drive($client);

$log = '';

/********STEP 1: Organize File Folder information**********/

$folderArray = findFolders($service);

$folders = $folderArray["folders"];
$folderNames = $folderArray["folderNames"];
$folderParents = $folderArray["folderParents"];

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
			echo("match\n");
			 
			// Successfully retrieved the file from Sheets. Check against local.
			$remoteContents = $service->files->export($file->getID(), 'text/csv', array('alt'=>'media'))->getBody();
			
			if($remoteContents != "" && $remoteContents != NULL)
			{
				//convert Google's CSV format to Firetongue's TSV via a local java program:
				$remoteContents = csv2tsv($remoteContents,"");
				
				//read the local version of the file in our git repository
				$localFilePath = $listfile['local_path'] . DIRECTORY_SEPARATOR . $listfile['local_file'];
				
				echo("local file path = " . $localFilePath . "\n");
				
				$localContents = readFileData($localFilePath);
				
				//if the files do not match
				if (compareTSV($remoteContents,$localContents,"") == false) {
				
					$log .= "New version of ". $listfile['local_file'] ." found at ". date('Y m d H:i:s') .". Attempting to commit...\n";
					
					$localPath = $listfile['local_path'];
						
					//ensure we're up to date with the remote repository
					$log .= runCommand('git reset --hard origin/master', $localPath);
					$log .= runCommand('git fetch', $localPath);
					$log .= runCommand('git pull', $localPath);
						
					// Update the local copy
					$local = @fopen($localFilePath, 'w');
					
					if(is_resource($local))
					{
						fwrite($local, $remoteContents);
						@fclose($local);
						
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
   @fclose("sheets.log");
}