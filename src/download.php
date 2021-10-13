#!/usr/bin/env php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

set_error_handler('catchError');

// Save program name
$argv0 = $argv[0];

// Parse command line options options
$offset  = 0;
$options = getopt("o:g:fswmdxvlth", [], $offset);

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
if (isset($options['m'])) verifyMD5();

// Trove HTTP Client
$client = getGuzzleHttpClient($trove_key);

// Get list of all trove games from the API
$trove_data = getTroveData($client);
if (isset($options['t'])) {
    // write trove file
    file_put_contents(TD_BASE_PATH . DIRECTORY_SEPARATOR . 'trove-metadata-' . date("Ymd-Hms") . '.json', json_encode($trove_data));
}

// Do deletes first
// TODO: implement, obvs
if (isset($options['x']) || isset($options['d'])) {
    $allfiles = scandir(TD_BASE_PATH);
    foreach ($allfiles as $dfile) {
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
        continue;
    }

    if (TD_VERBOSE) print "Processing $display [$game_code]...\n";

    foreach ($downloads as $os => $dl) {
        $file = $dl->url->web;
        $filename = TD_FLATTEN?basename($file):$file;
        $game = $dl->machine_name;
        $md5  = $dl->md5;

        $dl_path = TD_BASE_PATH . DIRECTORY_SEPARATOR . $os . DIRECTORY_SEPARATOR . $filename;
        // Cache the md5sum in a subdirectory alongside the downloads
        $cache_path = TD_META_PATH . DIRECTORY_SEPARATOR . $os . DIRECTORY_SEPARATOR . dirname($filename) . DIRECTORY_SEPARATOR . "." . basename($filename) . ".md5sum";

        // Check if this is an OS that we are excluding from download
        if (in_array($os, $exclude_os)) {
            print "   Skipping $os release (excluded)...\n";
            $file_results[$file] = "skip-os";
            continue;
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
                    $curl_resource,
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
            $filemd5 = readline("Keep file MD5? (Y/N): ");
            if (strtolower($filemd5) === "y") {
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
if (isset($options['l'])) {
    // write log file
    file_put_contents(TD_BASE_PATH . DIRECTORY_SEPARATOR . 'trove-dl-' . date("Ymd-Hms") . '.log.json', json_encode($file_results));
}

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
    print "\n";
    print "      -s              Unattended run, skip games w/mismatched hashes (default: prompt)\n";
    print "      -w              Unattended run, overwrite games w/mismatched hashes\n";
    print "      -m              Verify MD5 cache vs local files only (no Trove DLs) - not yet implemented\n";
    print "\n";
    print "      -d              Delete files no longer in trove (keep hashes) - not yet implemented\n";
    print "      -x              Expunge files no longer in trove (delete hashes) - not yet implemented\n";
    print "\n";
    print "      -v              Verbose output (beta)\n";
    print "      -l              Save download log to file\n";
    print "      -t              Save Trove list to file\n";
    print "\n";
    print "      -h              This help text\n\n";
    exit($exitval);
}


function verifyMD5()
// TODO: implement, obvs
{
    foreach (array("windows","mac","linux") as $os) {
        $metafiles = scandir();
    }
    exit(0);
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
