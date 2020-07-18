# Humble Trove Downloader

Quick and dirty script to download all games, for all operating
systems, from [Humble Trove](https://www.humblebundle.com/monthly/trove). You
need a Humble Monthly subscription to download these games.

Existing files have their md5 checksums checked versus what the Humble Bundle API
reports. If there is a mismatch, you'll need to delete or move the offending
file and run the script again.

# Setup

## Pre-requisites

Install [PHP](https://www.php.net/) and [Composer](https://getcomposer.org/). If
you are using Linux, these should be available from your distribution's package
repositories.

## Install Dependencies

After cloning this repository, run `composer` in the project's main directory
 to install dependencies:

```bash
$ composer install
```

# Usage

## API Key

See the [instructions here](https://github.com/talonius/hb-downloader/wiki/Using-Session-Information-From-Windows-For-hb-downloader) on
how to get a session key from your browser while logged into the Humble Bundle website. This script will not work without
it.

## Running the Script

```bash
$ ./src/download.php DOWNLOAD_PATH HUMBLE_API_KEY
```

Example (this is not a real key):

```bash
$ ./src/download.php /home/downloads/trove "eyJ1GzZJE0oqwEaunyOYX3yrlkFUxPJq8PFWCgkKOHM00\075|1566665561|JR7m2nO769sO2Je4C2fE"
```

## Excluding OS or Games

You can exclude operating systems with the `-o` parameter and games with the `-g`
parameter. To exclude multiple, specify them in a comma-separated list as follows:

```
$ ./src/download.php -o mac,linux -g game1,game2,game3 /home/downloads/trove "eyJ1GzZJE0oqwEaunyOYX3yrlkFUxPJq8PFWCgkKOHM00\075|1566665561|JR7m2nO769sO2Je4C2fE"
```

`-o` can be "mac", "linux", and/or "windows". `-g` should be the "machine name"
of the games, which are output in square brackets when downloading:

```
Processing The Flame in the Flood [theflameintheflood_trove]...
```

In the above example, you should use `theflameintheflood_trove` when using the
`-g` parameter.

You can also find this "machine name" value on the Humble Trove website. When
you click on the game you are planning to exclude, the URL in your browser will
change to the following format:

`https://www.humblebundle.com/subscription/trove?selected=theflameintheflood_trove`

The value of the `selected=` parameter in the URL is the same name you should
use when excluding games from download using this tool.

# Docker

A docker image is available so you don't need to setup PHP or deal with
dependencies:

```bash
$ docker run -it \
    -v /your/path/to/downloads/folder/:/downloads \
    -u $(id -u):$(id -g) \
    slashbunny/humble-trove-downloader:latest /downloads "eyJ1GzZJE0oqwEaunyOYX3yrlkFUxPJq8PFWCgkKOHM00\075|1566665561|JR7m2nO769sO2Je4C2fE"
```

# Help

### Error Message: "Trying to get property 'signed_url' of non-object"

Your API key is wrong. Double check is it correct and quote it the same way in the example under *Usage*.

