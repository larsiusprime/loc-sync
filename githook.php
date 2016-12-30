<?php
require_once('path/to/include.php');

define('LOC_PREFIX', 'path/to');

$body = file_get_contents("php://input"); // contents of webhook payload

$sig = $_SERVER['HTTP_X_HUB_SIGNATURE'];

$valid = validateSignature($body, WEBHOOK_SECRET, $sig);

if (!$valid)
{
	$log .= "Invalid signature on webhook payload\n";
}
else
{
	$json = json_decode($body, true);
	$commitUrl = $json['head_commit']['url'];
	$contents = '';
	$log = '';
	$matches = null;

	// The webhook doesn't give us the direct path to the file. To get that, we first need to request the commit page.
	if (!empty($commitUrl))
	{
	   $commitPage = fopen($commitUrl, 'r');
		while(is_resource($commitPage) && !feof($commitPage)) $contents .= fread($commitPage, 1024);
		fclose($commitPage);
		
		foreach($watchlist as $listfile)
		{
			$basicPath = $listfile['local_path'] . DIRECTORY_SEPARATOR . $listfile['local_file'];
			$basicPath = str_replace(LOCAL_ROOT, "", $basicPath);
			
			//Check if the filename was found in the payload:
			if (preg_match('/href="([^"]*blob[^"]*'. $listfile['local_file'] .')"/', $contents, $matches))
			{
				$remoteUrl = 'https://raw.githubusercontent.com'. str_replace("/blob", "", $matches[1]);
				 
				//Check if the basic path (including locale) matches the remote file
				if(strpos($remoteUrl,$basicPath) !== false)
				{
					// The rest of this only triggers if the watched file was part of the commit.
			 
					 $log .= "New version of ". $listfile['local_file'] ." committed at ". date('Y m d H:i:s') .". URL: ". $remoteUrl ."\n";
					
					 $localPath = LOC_PREFIX . DIRECTORY_SEPARATOR . $listfile['local_path'] . DIRECTORY_SEPARATOR . $listfile['local_file'];
					
					 $localContents = readFileData($localPath);
					 
					 $remoteContents = readFileData($remoteUrl);
					 
					 if (true || compareTSV($localContents, $remoteContents, LOC_PREFIX) == false) {
						
						$log .= "   TSV's do not match\n";
						
						// Update the local copy
						writeFileData($localPath, $remoteContents);
						
						// Upload to Google Sheets
						$client = getGoogleClient(LOC_PREFIX);

						$service = new Google_Service_Drive($client);
						
						/********Organize File Folder information**********/

						$folderArray = findFolders($service);
						
						$folders = $folderArray["folders"];
						$folderNames = $folderArray["folderNames"];
						$folderParents = $folderArray["folderParents"];
		   
						/**************************************************/
						
						// Find an existing version of the file in Sheets, if possible
						$optParams = array(
						  'q' => "name = '". $listfile['google_title'] ."'"
						);
						
						$getParams = array(
							'fields' => "name, parents"
						);
						
						$searchResults = $service->files->listFiles($optParams);
						
						$googleFileFound = false;
						$googleFileId = NULL;
						
						if (count($searchResults->getFiles())) {
							
							foreach ($searchResults->getFiles() as $file) {
								$fileId = $file->getId();
								$result = $service->files->get($fileId, $getParams);
								$resultName = $result->name;
								$resultParent = $result->parents[0];
								
								//make sure it's the right file by checking the intended vs fully resolved folder path:
								$resultPath = getFolderPath($resultParent, $folderNames, $folderParents);
								$intendedPath = $listfile['google_path'];
								
								if($resultPath == $intendedPath) {
									
									$googleFileFound = true;
									$googleFileId = $fileId;
								}
							}
						}
						
						$csvContents = tsv2csv($remoteContents, LOC_PREFIX);
						
						$file = new Google_Service_Drive_DriveFile();
						$file->setName($listfile['google_title']);
						$file->setFileExtension('.tsv');
						$file->setMimeType('text/csv');
						$file->setSize(strlen($csvContents));

						// Upload or replace the file
						if ($googleFileFound == false) {
						   $log .= "No file found in Sheets. Uploading new file.\n";
						   
						   //TODO: No idea why, but I can't create a new file from scratch using the 
						   //stupid @#$$^%! Google Drive API so this part will error-out
						   
						   //WORKAROUND: If you pre-populate your google drive with all the files you
						   //care about, it WILL sync all *modifications*
						   
						   try {
								$result = $service->files->create($file, array(
									'data' => $csvContents,
									'mimeType' => 'text/csv',
									'uploadType' => 'media',
								));
							}
							catch (Exception $e) {
								$log .= "   An error occured: " . $e->getMessage();
							}
						} else {
							$log .= "   Replacing Sheets file with new contents.\n";
							
							try {
								
								$file = new Google_Service_Drive_DriveFile();
								$result = $service->files->update($googleFileId, $file, array(
									'data' => $csvContents,
									'mimeType' => 'text/csv',
									'uploadType' => 'media',
								));
							} catch (Exception $e) {
								$log .= "   An error occured: " . $e->getMessage();
							}
						}
					}
					else
					{
						$log .= "   New ". $listfile['local_file'] ." matches local. No action performed.\n";
					}
				}
			}
		}
	}
}

if (LOG_ENABLE && !empty($log)) {
   $h = fopen(LOC_PREFIX . DIRECTORY_SEPARATOR . "githook.log", "a");
   fwrite($h, $log ."\n");
}

function validateSignature($payload, $secret, $signature)
{
	if(empty($signature))
	{
		return false;
	}
	
	if(empty($secret))
	{
		return false;
	}
	
	$hash = 'sha1=' . hash_hmac('sha1', $payload, $secret, false);
	
	return hash_equals($hash, $_SERVER['HTTP_X_HUB_SIGNATURE']);
}

