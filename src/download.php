#!/usr/bin/env php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

set_error_handler('catchError');

// Save program name
$argv0 = $argv[0];

// Parse command line options options
$offset  = 0;
$options = getopt("o:g:fdxswcmvlth", [], $offset);

// Remove command line options from argv, so that only the path/api key remain
$argv = array_splice($argv, $offset);

// There must be 2 remaining parameters after removing the options
if (count($argv) !== 2) {
    printUsage($argv0);
}

// Parameters to script
define("TD_BASE_PATH", $argv[0]);
$trove_key = $argv[1];

$exclude_os    = !empty($options['o']) ? explode(',', $options['o']) : [];
$exclude_games = !empty($options['g']) ? explode(',', $options['g']) : [];
define("TD_VERBOSE", isset($options['v']));
define("TD_FLATTEN", isset($options['f']));
define("TD_OVERWRITE", isset($options['w']));
define("TD_UNATTENDED", TD_OVERWRITE || isset($options['s']));
define("TD_CLEANUP", isset($options['c']));
define("TD_VERIFY", isset($options['m']));
define("TD_EXPUNGE", isset($options['x']));
define("TD_DELETE", TD_EXPUNGE || isset($options['d']));

define("TD_META_DIR", "_meta"); // set to "." to revert to original upstream functionality
define("TD_META_PATH", TD_BASE_PATH . DIRECTORY_SEPARATOR . TD_META_DIR);

// Help text
if (isset($options['h'])) {
    printUsage($argv0, 0);
}

// Ensure the provided path is valid
if (!is_dir(TD_BASE_PATH)) {
    print "ERROR: " . TD_BASE_PATH . " is not a valid directory or does not exist! \n";
    print "       Create the directory and try again.\n\n";

    printUsage($argv0);
}

// verify MD5 cache only
if (TD_CLEANUP || TD_VERIFY) checkMD5(TD_VERIFY);

// Trove HTTP Client
$client = getGuzzleHttpClient($trove_key);

// Get list of all trove games from the API
$trove_data = getTroveData($client);
if (isset($options['t'])) {
    // write trove file
    file_put_contents(TD_BASE_PATH . DIRECTORY_SEPARATOR . 'trove-metadata-' . date("Ymd-Hms") . '.json', json_encode($trove_data));
}

/* Create current local file list for deletes */
if (TD_DELETE) {
    if (TD_VERBOSE) print ("Creating local file list...\n");
    $local_files = array();
    foreach (array("windows", "mac", "linux") as $os) {
        if (in_array($os, $exclude_os)) {
            continue;
        }
        $os_files = getFilespec(TD_BASE_PATH . DIRECTORY_SEPARATOR . $os, 1);
        $local_files = array_merge($local_files, array_map(fn($a) => $os . DIRECTORY_SEPARATOR . $a, $os_files));
    }
}

// Then download new files
$count = 0;
$file_results = [];

foreach ($trove_data as $game) {
    $display   = $game->{'human-name'};
    $game_code = $game->machine_name;
    $downloads = $game->downloads;

    // Check if this is a game that we are excluding from download
    if (in_array($game_code, $exclude_games)) {
        print "Skipping $display [$game_code] (excluded)...\n";
        $file_results[$game_code] = "skip-game";
        // TODO: skip game for deletion as well! - maybe break out this file_results per download too?
        continue;
    }

    if (TD_VERBOSE) print "Processing $display [$game_code]...\n";

    foreach ($downloads as $os => $dl) {
        $file = $dl->url->web;
        $filename = TD_FLATTEN ? basename($file) : $file;
        $game = $dl->machine_name;
        $md5  = $dl->md5;

        $os_path = $os . DIRECTORY_SEPARATOR . $filename;
        $dl_path = TD_BASE_PATH . DIRECTORY_SEPARATOR . $os_path;
        // Cache the md5sum in a subdirectory alongside the downloads
        $cache_path = TD_META_PATH . DIRECTORY_SEPARATOR . $os . DIRECTORY_SEPARATOR . dirname($filename) . DIRECTORY_SEPARATOR . "." . basename($filename) . ".md5sum";

        // Check if this is an OS that we are excluding from download
        if (in_array($os, $exclude_os)) {
            print "   Skipping $os release (excluded)...\n";
            $file_results[$file] = "skip-os";
            continue;
        }

        // Get this file off our delete list, if it's on it
        if (TD_DELETE && ($idx = array_search($os_path, $local_files)) !== FALSE) {
            unset($local_files[$idx]);
        }

        // Ensure full path exists on disk
        if (!is_dir(dirname($dl_path))) {
            mkdir(dirname($dl_path), 0777, true);
        }
        if (!is_dir(dirname($cache_path))) {
            mkdir(dirname($cache_path), 0777, true);
        }

        if (TD_VERBOSE) print "   Checking $os ($file)\n";

        // File already exists- Check md5sum
        if (file_exists($dl_path)) {
            if (TD_VERBOSE) print "    $filename already exists! Checking md5sum for $dl_path";

            $file_date  = filemtime($dl_path);
            $cache_date = file_exists($cache_path) ? filemtime($cache_path) : 0;

            // If cache is newer than file, use it
            // TODO: probably should be same age or newer? Prob doesn't matter
            if ($cache_date > $file_date) {
                if (TD_VERBOSE) print " [Using Cache] ...\n";
                $existing_md5 = file_get_contents($cache_path);

            } else {
                if (TD_VERBOSE) print " [Generating MD5] ...\n";
                $existing_md5 = md5_file($dl_path);

                // Cache md5sum to file
                file_put_contents($cache_path, $existing_md5);
            }

            if ($existing_md5 === $md5) {
                if (TD_VERBOSE) print "        Matching md5sum $md5 at $dl_path \n\n";
                continue;
            } else {
                print "      Wrong md5sum at $dl_path \n";
                if (TD_VERBOSE) print "        ($md5 vs $existing_md5)\n";
                if (!TD_UNATTENDED) {
                    print "      Overwrite or skip? (O/S): ";
                    $ow = readline(); // readline $prompt param wasn't printing through Docker exec?
                }
                if (! (TD_OVERWRITE || substr(strtolower(trim($ow ?? "")),0,1)=="o")) {
                    print "      Skipping file $filename \n\n";
                    // we'll pull the md5 again next time anyway -- maybe they will have corrected it
                    unlink($cache_path);
                    $file_results[$file] = "skip-badmd5";
                    continue;
                }
            }
        } else {
            if (TD_VERBOSE) print "    $filename does not exist\n";
        }

        print "    Downloading to $dl_path... \n";

        $url = getDownloadLink($client, $game, $file);

        // Download file
        $client->request(
            'GET',
            $url,
            [
                'sink'     => $dl_path,
                'progress' => function(
                    $download_total,
                    $downloaded_bytes,
                    $upload_total,
                    $uploaded_bytes
                ) {
                    if ($download_total === 0) {
                        $pct = 0;
                    } else {
                        $pct = number_format(($downloaded_bytes / $download_total) * 100, 2);
                    }

                    print "\r    Progress: " . $pct . '%';
                }
            ]
        );

        print "\n";
        if (TD_VERBOSE) print "Verifying file... ";
        $new_md5 = md5_file($dl_path);
        if ($md5 === $new_md5) {
            if (TD_VERBOSE) print "Matching md5sum $md5 at $dl_path \n";
            file_put_contents($cache_path, $new_md5);
            $file_results[$file] = "dl-verified";
        } else {
            print "Mismatched md5sum ($new_md5 vs $md5) at $dl_path \n";
            // TODO: do we really need this option? Whether to keep the *file* makes more sense... or maybe not?
            $keep_md5 = readline("Keep file MD5? (Y/N): ");
            if (strtolower($keep_md5) === "y") {
                file_put_contents($cache_path, $new_md5);
                $file_results[$file] = "dl-forcedmd5";
            } else {
                if (file_exists($cache_path)) {
                    unlink($cache_path);
                    $file_results[$file] = "dl-unverified";
                }
            }
        }

        if (TD_VERBOSE) print "\n";

        $count++;
    }
}

print "Processed $count games\n";

if (TD_DELETE) {
    print ("Deleting games that are no longer in Trove...\n");
    foreach ($local_files as $del_file) {
        if (TD_VERBOSE) print ("    Deleting $del_file\n");
        unlink(TD_BASE_PATH . DIRECTORY_SEPARATOR . $del_file);
        // TODO: remove empty directories?
        // TODO: implement delete logging
        if (TD_EXPUNGE) {
            $del_md5 = TD_META_PATH . DIRECTORY_SEPARATOR . dirname($del_file) . DIRECTORY_SEPARATOR . "." . basename($del_file) . ".md5sum";
            if (file_exists($del_md5)) {
                if (TD_VERBOSE) print ("    Deleting $del_md5\n");
                unlink($del_md5);
            }
        }
    }
}

if (isset($options['l'])) {
    // write log file
    file_put_contents(TD_BASE_PATH . DIRECTORY_SEPARATOR . 'trove-dl-' . date("Ymd-Hms") . '.log.json', json_encode($file_results));
}


/************
 End main
************/


/**
 * Prints usage of script
 */
function printUsage($program_name, $exitval = 1) {
    print "Usage: $program_name [options] <path> <api_key>\n\n";
    print "    path    - Base path to download files\n";
    print "    api_key - Humble bundle session from your browser's cookies\n\n";
    print "    options:\n";
    print "      -o <os_list>    Comma-separated list of OS to exclude (windows,linux,mac)\n";
    print "      -g <game_list>  Comma-separated list of games to skip (produced in the output of this program in square brackets)\n";
    print "      -f              Flatten directory structure for received games (default: subdirectories are preserved)\n";
    print "      -d              Delete files no longer in trove (keep hashes) ***\n";
    print "      -x              Expunge files no longer in trove (delete hashes) ***\n";
    print "                          *** BE CAREFUL to use the correct -f setting for your repo with these commands!\n";
    print "\n";
    print "      -s              Unattended run, skip games w/mismatched hashes (default: prompt)\n";
    print "      -w              Unattended run, overwrite games w/mismatched hashes\n";
    print "\n";
    print "      -c              Check/clean up MD5 cache only, delete old entries (no DL)\n";
    print "      -m              Like -c, but also verifies MD5 cache vs local files (slow)\n";
    print "\n";
    print "      -v              Verbose output\n";
    print "      -l              Save a log of the current run to file when done\n";
    print "      -t              Save any DL'd Trove JSON data to file when done\n";
    print "\n";
    print "      -h              This help text\n\n";
    exit($exitval);
}


/**
 * MD5 hash cache cleanup and verification
 */
function checkMD5($verify = false)
// TODO: finish MD5 correction/generation
// TODO: implement verification logging
{
    $dl_files = $md5_files = array();
    foreach (array("windows","mac","linux") as $os) {
        $dl_dir = getFilespec(TD_BASE_PATH . DIRECTORY_SEPARATOR . $os, 1);
        $md5_dir = getFilespec(TD_META_PATH . DIRECTORY_SEPARATOR . $os, 2);
        $ostag = fn(&$a) => $a = $os . DIRECTORY_SEPARATOR . $a;
        array_walk($dl_dir, $ostag);
        array_walk($md5_dir, $ostag);
        $dl_files = array_merge($dl_files, $dl_dir);
        $md5_files = array_merge($md5_files, $md5_dir);
    }
    foreach ($md5_files as $md5_file) {
        $md5_filemap[$md5_file] = file_get_contents(TD_META_PATH . DIRECTORY_SEPARATOR . $md5_file);
    }
    print ($verify ? "Verifying hash cache (this will take a while)...\n" : "Checking existing hash cache...\n");
    foreach ($dl_files as $dl_file) {
        if (TD_VERBOSE) {
            print ("  Checking file $dl_file\n");
        }
        $md5_path = dirname($dl_file) . DIRECTORY_SEPARATOR . "." . basename($dl_file) . ".md5sum";
        if (!isset($md5_filemap[$md5_path])) {
            print ("    $dl_file hash not found in cache\n");
            // print ("Generating...\n");
        }
        else {
            if ($verify) {
                $dl_md5 = md5_file(TD_BASE_PATH . DIRECTORY_SEPARATOR . $dl_file);
            }
            if ($verify && $md5_filemap[$md5_path] != $dl_md5) {
                print ("    incorrect hash for $dl_file\n");
                // print ("Fixing...\n");
            }
            unset($md5_filemap[$md5_path]);
        }
    }
    print("Cleaning up unused hash files...\n");
    foreach ($md5_filemap as $md5_file=>$hashmd5) {
        print ("  Deleting $md5_file\n");
        unlink(TD_META_PATH . DIRECTORY_SEPARATOR . $md5_file);
    }
    exit(0);
}


/**
 * Returns a recursive list of specified files in the passed base+prefix directory, with dir prefix
 * $filespec = 0 for all files, 1 for all except .md5sum, 2 for only .md5sum
 * TODO: a user-edited datastore with a circular symlink in it will almost certainly break this
 */
function getFilespec($dir_base, $filespec=0, $dir_prefix="") {
    $this_base = $dir_base . ($dir_prefix == "" ? "" : DIRECTORY_SEPARATOR) . $dir_prefix;
    $this_files = scandir($this_base);
    $all_files = array();
    foreach ($this_files as $currfile) {
        if ((substr($currfile, -1) == ".") ||
            ($filespec == 1 && substr($currfile, -7) == ".md5sum") ||
            ($filespec == 2 && substr($currfile, -7) != ".md5sum")) {
            continue;
        }
        $prefixed_file = $dir_prefix . ($dir_prefix == "" ? "" : DIRECTORY_SEPARATOR) . $currfile;
        if (is_dir($this_base . DIRECTORY_SEPARATOR . $currfile)) {
            $all_files = array_merge($all_files, getFilespec($dir_base, $filespec, $prefixed_file));
        } else {
            $all_files[] = $prefixed_file;
        }
    }
    return $all_files;
}


/**
 * Creates a Guzzle HTTP Client for interacting with the HB API
 */
function getGuzzleHttpClient($session_key)
{
    $cookies = [
        '_simpleauth_sess' => '"' . $session_key . '"',
    ];

    $cookie_jar = \GuzzleHttp\Cookie\CookieJar::fromArray(
        $cookies, 'humblebundle.com'
    );

    $client = new GuzzleHttp\Client([
        'base_uri' => 'https://www.humblebundle.com/api/v1/',
        'cookies'  => $cookie_jar,
    ]);

    return $client;
}


/**
 * Gets data for Trove from the HB API
 */
function getTroveData($client)
{
    $page_num   = 0;
    $trove_data = [];

    while (true) {
        if (TD_VERBOSE) print "Fetching game list (page: $page_num)\n";

        // Download each page of trove results
        $page_data = json_decode(
            $client->request('GET', 'trove/chunk?property=start&direction=desc&index=' . $page_num)->getBody()
        );

        // If results are empty, return data
        if (empty($page_data)) {
            return $trove_data;
        }

        // Combine results
        $trove_data = array_merge($trove_data, $page_data);

        $page_num++;

        // Prevent possible endless loop if something changes with the API
        if ($page_num > 10) {
            print "We fetched over 10 pages- Something may be wrong- Exiting\n";
            exit(1);
        }
    }
}


/**
 * Returns download URL for given user, game and file (win, mac, linux, etc)
 */
function getDownloadLink($client, $game, $file) {

    $result = json_decode(
        $client->request('POST', 'user/download/sign',
            [
                'form_params' => [
                    'machine_name' => $game,
                    'filename'     => $file,
                ],
            ]
        )->getBody()
    );

    return $result->signed_url;
}


/**
 * Handle any notices/warnings/errors
 */
function catchError($errNo, $errStr, $errFile, $errLine) {
    print "$errStr in $errFile on line $errLine\n";

    exit(1);
}
