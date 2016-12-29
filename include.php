<?php
define('GOOGLE_API_PATH', '/home/path/to/google-api-php-client/src');
define('ACCOUNT_EMAIL', 'your.name@example.com');
define('COMMIT_MESSAGE', 'Automated sync from Google Sheets');
define('PUSH_COMMAND', 'push -u origin master');
define('CREDENTIAL_PATH', 'your_credential_file.json');
define('LOG_ENABLE', true);
define('GOOGLE_ROOT', 'path/to/files/on/google/drive/');
define('LOCAL_ROOT', 'path/to/files/on/local/git/repo/');
define('STORY_SUFFIX', '/story content');
define('UI_SUFFIX', '/ui content');
define('WEBHOOK_SECRET', 'your_webhook_secret_goes_here');

//Define all the languages you want to watch files for
$languages = array(
	array(
		'name'   => 'English',
		'code'   => 'en-US'
	),
	array(
		'name'   => 'Spanish',
		'code'   => 'es-ES',
	),
	array(
		'name'   => 'German',
		'code'   => 'de-DE',
	),
	array(
		'name'   => 'French',
		'code'   => 'fr-FR',
	),
	array(
		'name'   => 'Italian',
		'code'   => 'it-IT',
	),
	array(
		'name'   => 'Japanese',
		'code'   => 'ja-JP',
	),
	array(
		'name'   => 'Korean',
		'code'   => 'ko-KR',
	),
	array(
		'name'   => 'Russian',
		'code'   => 'ru-RU',
	),
	array(
		'name'   => 'Czech',
		'code'   => 'cs-CZ',
	),
);

//Define all the files that exist in your project
$files = array(
	"achievements.tsv",
	"scripts.tsv",
	"journal.tsv",
	"achievements.tsv",
	"bonus.tsv",
	"core.tsv",
	"defender.tsv",
	"enemy.tsv",
	"enemy_plus.tsv",
	"items.tsv",
	"maps.tsv",
	"status_effects.tsv",
);

//Specify which files are "story" content (the rest are "ui" content)
//(If everything is the same category then just leave this empty)
$story = array(
	"scripts.tsv" => TRUE,
	"journal.tsv" => TRUE,
);

//Construct the watch list from the above parameters
$watchlist = getWatchList($languages, $files, $story);

set_include_path(get_include_path() . PATH_SEPARATOR . GOOGLE_API_PATH);
require GOOGLE_API_PATH .'/../vendor/autoload.php';

function getGoogleClient($cwd) {
	
   if($cwd == NULL) $cwd = "";
   
   if($cwd == "")
   {
	   $credPath = CREDENTIAL_PATH;
   }
   else
   {
	   $credPath = $cwd . DIRECTORY_SEPARATOR . CREDENTIAL_PATH;
   }
	
   // Get the API client and construct the service object.
   $scopes = array('https://www.googleapis.com/auth/drive');
   
   $client = new Google_Client();
   
   $client->setScopes($scopes);
   $client->setAuthConfig($credPath);
   
   if ($client->isAccessTokenExpired()) {
      $client->refreshTokenWithAssertion();
   }
   
   return $client;
}

function runCommand($command, $cwd) {
	// Run a command from a given working directory
   $descriptorspec = array(
      1 => array('pipe', 'w'),
      2 => array('pipe', 'w'),
   );
   $pipes = array();
   $resource = proc_open($command, $descriptorspec, $pipes, $cwd);
   $stdout = stream_get_contents($pipes[1]);
   $stderr = stream_get_contents($pipes[2]);
   foreach ($pipes as $pipe) {
      fclose($pipe);
   }
   $status = trim(proc_close($resource));
   return $stdout;
}

function getGooglePath($isStory, $lang)
{
	// Get the google folder path for a given language
	if($isStory == true)
	{
		return GOOGLE_ROOT . $lang['name'] . STORY_SUFFIX;
	}
	return GOOGLE_ROOT . $lang['name'] . UI_SUFFIX;
}

function getLocalPath($lang)
{
	// Get the local path for a given language
	return LOCAL_ROOT . $lang['code'];
}

function getWatchList($languages, $files, $story)
{
	// Construct the list of files to watch
	$returnArr = array();
	foreach($languages as $language) {
		$arr = getFiles($language, $files, $story);
		foreach($arr as $arrEntry) {
			$returnArr[] = $arrEntry;
		}
	}
	return $returnArr;
}

function getFiles($lang, $files, $story)
{
	// Get the individual details for a file
	$arr = array();
	foreach ($files as $file) {
		$isStory = $story[$file] == TRUE;
		$arr[] = array(
			'google_title'	=> $file,
			'google_path'   => getGooglePath($isStory, $lang),
			'local_path'    => getLocalPath($lang),
			'local_file'    => $file,
		);
	}
	return $arr;
}

function getFolderPath($id, $names, $parents)
{
	// Resolve a file's location in google drive's folders, returning a "path" based on parent ID's
	$returnStr = $names[$id];
	$nextName = "blah";
	while($nextName != "" && $nextName != NULL)
	{
		$id = $parents[$id][0];
		$nextName = $names[$id];
		if($nextName != "" && $nextName != NULL){
			$returnStr = $nextName . "/" . $returnStr;
		}
	}
	return $returnStr;
}

function compareTSV($a, $b, $cwd)
{
	if($a == $b) return true;
	
	if($cwd == NULL) $cwd = '';
	
	$local = fopen("__TEMP__A__.tsv", 'w');
	fwrite($local, $a);
	fclose($local);
	$local = fopen("__TEMP__B__.tsv", 'w');
	fwrite($local, $b);
	fclose($local);
	$result = runCommand('java -jar tsvCompare.jar __TEMP__A__.tsv __TEMP__B__.tsv',$cwd);
	
	echo("compare TSV result = " . $result . "\n");
	
	unlink("__TEMP__A__.tsv");
	unlink("__TEMP__B__.tsv");
	
	if($result == 1) return true;
	if($result == 2) return true;
	
	return false;
}

function csv2tsv($content, $cwd)
{
	//convert Google Sheets CSV file into Firetongue-compatible TSV file
	
	if($cwd == NULL)
	{
		$cwd = '';
	}
	
	$localTSV = "";
	$localCSV = "";
	
	if($cwd == '')
	{
		$localTSV = "__TEMP__.tsv";
		$localCSV = "__TEMP__.csv";
	}
	else
	{
		$localTSV = $cwd . DIRECTORY_SEPARATOR . "__TEMP__.tsv";
		$localCSV = $cwd . DIRECTORY_SEPARATOR . "__TEMP__.csv";
	}
	
	writeFileData($localCSV, $content);
	
	echo(runCommand('java -jar csv2tsv.jar __TEMP__.csv __TEMP__.tsv',$cwd));
	
	$localContents = readFileData($localTSV);
	
	unlink($localCSV);
	unlink($localTSV);
	
	return $localContents;
}

function tsv2csv($content, $cwd)
{
	//convert Firetongue-compatible TSV file into Google Sheets CSV file
	
	if($cwd == NULL) $cwd = '';
	
	$localTSV = "";
	$localCSV = "";
	
	if($cwd == "")
	{
		$localTSV = "__TEMP__.tsv";
		$localCSV = "__TEMP__.csv";
	}
	else
	{
		$localTSV = $cwd . DIRECTORY_SEPARATOR . "__TEMP__.tsv";
		$localCSV = $cwd . DIRECTORY_SEPARATOR . "__TEMP__.csv";
	}
	
	writeFileData($localTSV, $content);
	
	echo(runCommand('java -jar csv2tsv.jar __TEMP__.tsv __TEMP__.csv -reverse',$cwd));
	
	$localContents = readFileData($localCSV);
	
	unlink($localCSV);
	unlink($localTSV);
	
	return $localContents;
}

function findFolders($service)
{
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
		
		return array(
			"folders"       => $folders,
			"folderNames"   => $folderNames,
			"folderParents" => $folderParents,
		);
	}
}

function readFileData($path)
{
	$contents = "";
	$file = @fopen($path, 'r');
	while(is_resource($file) && !feof($file)) $contents .= @fread($file, 1024);
	@fclose($file);
	return $contents;
}

function writeFileData($path, $contents)
{
	$file = @fopen($path, 'w');
	@fwrite($file, $contents);
	@fclose($file);
}