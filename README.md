# loc-sync
A tool that synchronizes localization files between Google Sheets and a Git repository.

by [Adam Perry](https://www.twitter.com/hoursgoby) and [Lars Doucet](https://www.github.com/larsiusprime)

**What it does:**

- When you push TSV files to git loc-sync updates the corresponding google sheets files on google drive
- A remote server periodically polls google sheets and checks for differences and pushes changes to git as TSV files

**What it's good for:**

- Translators prefer to work directly in spreadsheets rather than raw text editors
- Translators prefer cloud storage like google drive over git
- Programmers like me prefer git for robust version control & change history
- Programmers like me sometimes make tweaks to localization files directly in the raw text form
- Loc-sync keeps all localization files in "one" place to cut down on duplication errors
- Loc-sync ensures everything is always in UTF-8 format
- Loc-sync ensures everything is always in [Firetongue](https://github.com/larsiusprime/firetongue) compatible TSV files
- Loc-sync avoids spurious and/or infinite looping syncs by fully parsing the Google Sheets / Git TSV files and doing the comparison on a cell-by-cell level rather than just naively comparing the full files

---------------------

# Instructions

## Repository & Google Drive setup

1. Create a github repository to store your TSV files. [Here's mine](https://github.com/larsiusprime/defendersquest-loc) as an example.
2. Create a parallel copy of this data on google drive (sadly I don't have a way to automate this process yet)
   - Create a new google sheet for each TSV file you have on github
   - Make sure the google sheet filename matches its github counterpart
   - Paste in the TSV content (a properly formatted TSV file can be directly pasted from e.g. Notepad++ and google sheets will automatically *Do The Right Thing*)
3. [Set up a webhook](https://developer.github.com/webhooks/) for your github repo
   - Set the trigger to "Just the push event"
   - Set the content type to "application/json"
   - Set the payload URL to something like `www.example.com/locsync/githook.php`, but on a website you actually control.
   - Pick a secret code, enter it here, and save it for use later on.

## Github user setup

1. Create a dedicated github user with push access to your github repo
2. [Set up an SSH key for it](https://help.github.com/articles/generating-an-ssh-key/) on the server you're going to install loc-sync on.

## Google user setup

1. Log in to the Google account associated with your desired Google Sheets. 
2. Browse to the Google Developers Console (https://console.developers.google.com). You may have to perform some initial setup if you've never used the Google Developers Console.
  - Create a new project.
  - Under Credentials, "Create credentials," creating a service account. Generate a Service account key as JSON. Save the result.
  - Note that the resulting service account is associated with an email address (something@appspot.gserviceaccount.com). We will use this later.

## Server setup

0. Obtain a linux-based web server
   - Requires PHP (I have version 5.6.29)
   - Requires Java (I have version 1.7.0_101)
   - Requires git (I have version 2.7.3)
1. Copy `include.php`, `poll_sheets.php` and the two `.jar` files to the same directory, doesn't need to be www-accessible 
2. Copy `githook.php` to a www-accessible directory of your choice (should match step 3. from "file setup")
3. Login to your server
4. Clone your github repository into a directory of your choice. This directory doesn't need to be www-accessible
5. Install the Google API PHP client in the location of your choice:
  ```
  git clone https://github.com/google/google-api-php-client
  ```
6. Edit the defines in `include.php`:
   - Define GOOGLE_API_PATH as the path you used in the previous step. Use the full path (do not use ~). The path should end in "src".
   - Define ACCOUNT_EMAIL as the email address associated with your Google account.
   - Change COMMIT_MESSAGE if you like. Don't use quotation marks here.
   - Change PUSH_COMMAND if needed.
   - Define CREDENTIAL_PATH as the path to the credential json file you created in step 2.
   - Change LOG_ENABLE to false if you don't want logging. You should at least leave the logs running until you determine that everything is working.
   - Define GOOGLE_ROOT as the full path to the google drive folder containing all your files, starting at your google drive root
   - Define LOCAL_ROOT as the relative path to all your files in the git repo, starting from include.php's directory
   - Define STORY_SUFFIX as the google drive folder where you store "story" files as opposed to "ui" files
   - Define UI_SUFFIX as the google drive folder where you store non-"story" files (if you don't care about this distinction, just put all your files in the google drive root and then define these both as "")
   - Define WEBHOOK_SECRET as the secret code you picked in step 3. of "file setup"
   
7. Edit the data structures in `include.php`:
  - Change the `$languages` array to match the languages you want to watch files for ("name" should match the name of the folder on Google Drive, and "code" should match the name of the folder on Github)
  - Change the `$files` array to match the files that exist in your project
  - Change the `$story` array so that any files that count as "story" content have keys set to `TRUE` -- you don't have to explicitly mark anything else (if you don't care about the ui/story distinction just leave this array blank).

8. Edit `githook.php`:
 - Change the path in the `require_once` call on line 2 to properly point to your `include.php`
 - Define `LOC_PREFIX` to the relative path that gets you to `include.php`'s directory

9. Add a cron job for `poll_sheets.php`
  - On the command line, type `crontab -e`
  - Add a new line: `0 * * * * php /path/to/poll_sheets.php` (using your actual path, obviously)
  - That will poll Sheets hourly. If you want to poll every other hour instead, you would use `0 */2 * * *`.

## Example setup

Github:
```
en-US/                  <--English files
en-US/menus.tsv
en-US/items.tsv
en-US/enemies.tsv
en-US/cutscenes.tsv
en-US/lore.tsv
ja-JP/                  <--Japanese files
ja-JP/menus.tsv
ja-JP/items.tsv
ja-JP/enemies.tsv
ja-JP/cutscenes.tsv
ja-JP/lore.tsv
```

Google drive:
```
myproject/                                       <--project root on google drive
myproject/English                                <--English files
myproject/English/story content/                 <--story files (English)
myproject/English/story content/cutscenes.tsv
myproject/English/story content/lore.tsv
myproject/English/ui content/                    <--ui files (English)
myproject/English/ui content/menus.tsv
myproject/English/ui content/items.tsv
myproject/English/ui content/enemies.tsv
myproject/Japanese                               <--Japanese files
myproject/Japanese/story content/                <--story files (Japanese)
myproject/Japanese/story content/cutscenes.tsv
myproject/Japanese/story content/lore.tsv
myproject/Japanese/ui content/                   <--ui files (Japanese)
myproject/Japanese/ui content/menus.tsv
myproject/Japanese/ui content/items.tsv
myproject/Japanese/ui content/enemies.tsv
```

Server:
```
/home/                            <--your user's home directory root
/home/www/                        <--your web directory
/home/locsync/                    <--see step 1. 
/home/locsync/include.php
/home/locsync/poll_sheets.php
/home/locsync/mygitrepo/          <--see step 4.
/home/www/locsync/githook.php     <--see step 2.
/home/google-api-php-client/      <--see step 5.
```

---------------------

# Notes

1. For sanity's sake, this project imposes certain formatting restrictions.

- UTF-8 *only*. Any other format is not guaranteed to work and in fact *probably won't*.
- TSV files *only*. 
  - Cells are separated by a single Tab character (0x09 in UTF-8)
  - Rows are terminated by an endline. Both unix (LF) & windows-style (CRLF) will work.
  - If you want a Tab character in the middle of a cell, tough. Use some replaceable token instead.
  - If you want an endline character in the middle of a cell, tough. Use some replaceable token instead.
  
2. This project depends on two `.jar` files -- `csv2tsv.jar` and `tsvCompare.jar` to perform certain high-precision UTF-8 text manipulation tasks. These binaries are included in this distro, but you can also compile them from source yourself if you want using Haxe and the hxjava library.
 - csv2tsv takes two filenames as input, and attempts to load the first file, parse it as CSV, transform it to TSV, and output to the second filename.
 - csv2tsv can also be run in reverse to transform TSV format to CSV.
 - tsvCompare takes two filenames as input, attempts to load & parse them both as TSV, and compare on a cell-by-cell basis. This will ignore any spurious differences (endline style, etc) that does not result in any actual difference on the cell level

3. The CSV format used by csv2tsv is specifically the one that Google Sheets exports. As near as I can tell, it behaves like this:
 - cells are separated by a single comma (0x2C in UTF-8)
 - cells that contain a comma are *quoted*
 - cells that contain a quotation mark (0x22 in UTF-8) are *quoted*
 - all other cells are *unqouted*
 - *quoted* cells begin and end with a quotation mark character
 - any quotation marks found inside of cells are escaped by doubling them: `"` becomes `""`
 - you don't really have to worry about this CSV stuff as it's all done internally, but it's here for completeness' sake
 - Examples:
 
 |John Smith|Dwayne "The Rock" Johnson|Fred Savage|
 |---|----|---|
 ```
 John Smith,"Dwayne ""The Rock"" Johnson",Fred Savage
 ```
 |Columbo|Murder, She Wrote|Matlock|
 |---|---|---|
 ```
 Columbo,"Murder, She Wrote",Matlock
 ```
