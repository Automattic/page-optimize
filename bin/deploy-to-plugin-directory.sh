#!/usr/bin/env bash

# Deploys the latest plugin version to the .org plugin directory
#
# Before deploying, this script checks to make sure the new version...
# - Is greater than the previous version
# - Matches "Stable version:" in readme.txt
# - Is not more than one digit different than the previous version (just a warning)
# - Contains new changes
# - Is not yet tagged in the plugin directory SVN
#
# When deploying, this script...
# - Adds all new files to SVN
# - Removes all deleted files from SVN
#

set -Eeuo pipefail
trap "cleanup" ERR INT EXIT

function cleanup {
	EXIT_CODE=$?
	echo -e "\nCleaning up before exit.\n"
	rm -rf "$PLUGIN_UPDATE_LOCATION"
	echo -e "Done!\n"
	# Avoids loop where trap will fire again:
	trap "" ERR INT EXIT
	exit $EXIT_CODE
}

function wp_plugin_version {
	echo "$( < "$1" grep -E "[[:blank:]]*Version:[[:blank:]]*[0-9]" | cut -d: -f2 | grep -Eo "[0-9]+\.[0-9]+\.[0-9]+" )"
}

# Set up colors for shell output.
RED=`tput setaf 1`
GREEN=`tput setaf 2`
YELLOW=`tput setaf 3`
RESET=`tput sgr0`

# Get script parent directory. https://stackoverflow.com/a/246128
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" >/dev/null 2>&1 && pwd )"

PLUGIN_PATH="$( realpath "$SCRIPT_DIR/.." )"
PLUGIN_UPDATE_LOCATION="$SCRIPT_DIR/dotorg-svn-deploy"

# Get the production version from the mu-plugins loader file.
NEW_LOADER_FILE="$PLUGIN_PATH/page-optimize.php"
NEW_VERSION="$( wp_plugin_version "$NEW_LOADER_FILE" )"

GIT_CURRENT_BRANCH="$( git branch | grep "* " | cut -c 3- )"
if [[ "master" != $GIT_CURRENT_BRANCH ]] ; then
	echo -e "${RED}Please switch to the master branch before deploying.$RESET"
	exit 1
fi

GIT_MASTER_REMOTE="$( git rev-parse --abbrev-ref --symbolic-full-name @{u} | cut -d/ -f 1 )"
GIT_MASTER_LOCAL_COMMIT="$( git rev-parse master )"
GIT_MASTER_REMOTE_COMMIT="$( git ls-remote $GIT_MASTER_REMOTE master | cut -f1 )"
if [[ $GIT_MASTER_LOCAL_COMMIT != $GIT_MASTER_REMOTE_COMMIT ]] ; then
	echo -e "${RED}Local master does not match $GIT_MASTER_REMOTE/master.$RESET"
	exit 1
fi

GIT_TAG="v$NEW_VERSION"
if ! git rev-parse "$GIT_TAG" >/dev/null 2>&1; then
	echo -e "${RED}Please create and push '$GIT_TAG' for the new release.$RESET"
	exit 1
fi

if ! git ls-remote --exit-code --tags "$GIT_MASTER_REMOTE" "$GIT_TAG" 2>&1 >/dev/null; then
	echo -e "${RED}Please push the '$GIT_TAG' to the '$GIT_MASTER_REMOTE' git remote.$RESET"
	exit 1
fi

GIT_TAG_COMMIT="$( git ls-remote origin v0.4.1 | cut -f1 )"
if [[ $GIT_TAG_COMMIT != $GIT_MASTER_LOCAL_COMMIT ]] ; then
	echo -e "${RED}The '$GIT_TAG' tag does not match master.$RESET"
	exit 1
fi

TRUNK="$PLUGIN_UPDATE_LOCATION/trunk"
DOTORG_PLUGIN_URL="http://plugins.svn.wordpress.org/page-optimize"

echo -e "Checking out the .org plugin repo...\n"
mkdir "$PLUGIN_UPDATE_LOCATION"
cd "$PLUGIN_UPDATE_LOCATION"
# Only copies trunk to the directory.
svn co -q "$DOTORG_PLUGIN_URL/trunk"

if [ ! -d "trunk" ] ; then
	echo -e "${RED}Couldn't clone the SVN repository.${RESET}\n"
	exit 1
fi

# Grab the current version from the plugin loader file before applying the update.
OLD_LOADER_FILE="$TRUNK/page-optimize.php"
if [[ -e "$OLD_LOADER_FILE" ]]; then
	OLD_VERSION="$( wp_plugin_version "$OLD_LOADER_FILE" )"
else
	OLD_VERSION='None'
fi

# Rsync is faster than cp, and it can handle deleting files in the target
# directory. We also exclude hidden files so that SVN continues working.
rsync -a --delete --exclude=".*" --exclude="bin" "$PLUGIN_PATH/" "$TRUNK/"

cd "$TRUNK"

CHANGES=`svn status`
if [ -z "$CHANGES" ] ; then
	echo -e "No changes between .org and this working copy. Exiting.\n"
	exit 1
fi

echo -e "Adding/removing files from svn (if applicable)...\n"

# Adds all unversioned, but not ignored files.
svn st | { grep '^\?' || test $? = 1; } | sed 's/^\? *//' | xargs -I% svn add %

# Removes all files which have been deleted.
svn st | { grep '^\!' || test $? = 1; } | sed 's/! *//' | xargs -I% svn rm %

echo -e "\nThese files have changed since the last update:\n"
svn st
echo -e "\n"

NEW_VERSION_URL="$DOTORG_PLUGIN_URL/tags/$NEW_VERSION"
# This value should contain 404 if the version does NOT exist.
NEW_VERSION_RESPONSE=`curl --write-out %{http_code} --silent --output /dev/null "$NEW_VERSION_URL"`

README_FILE="$TRUNK/readme.txt"
STABLE_VERSION="$( < "$README_FILE" grep -E "[[:blank:]]*Stable tag:[[:blank:]]*[0-9]" | cut -d: -f2 | grep -Eo "[0-9]+\.[0-9]+\.[0-9]+" )"

SHOULD_STOP=false
if [[ $NEW_VERSION_RESPONSE != "404" ]] ; then
	echo -e "${RED}The new version ($NEW_VERSION) has already been tagged. Please update the version before committing.${RESET}\n"
	SHOULD_STOP=true
fi

if [[ $STABLE_VERSION != $NEW_VERSION ]] ; then
	echo -e "${RED}The new version ($NEW_VERSION) does not match the stable tag ($STABLE_VERSION) in readme.txt.${RESET}\n"
	SHOULD_STOP=true
fi

echo -e "Version info:\n\tPrevious Version: $OLD_VERSION\n\tNew Version (defined in plugin loader): $NEW_VERSION\n\tStable Tag (defined in readme.txt): $STABLE_VERSION\n"

if [ "$SHOULD_STOP" = true ] ; then
	echo -e "Exiting -- please fix the errors shown above ${RED}in GitHub${RESET} before continuing.\n"
	exit 1
fi

echo -e "The new version ($NEW_VERSION) has not been created yet. Everything looks good!\n"

read -p "Are you sure you want to submit an update? (type y if you're sure): " -r
echo
if [[ $REPLY = 'y' ]]
then
	cd "$TRUNK"
	echo -e "Enter your .org username for the SVN commit:"
	read USERNAME
	MESSAGE="Update Page Optimize to $NEW_VERSION"
	# Use actual copy of svn instead of the sandbox alias to avoid wpcom checks.
	/usr/bin/svn ci --username "$USERNAME" -m "$MESSAGE"
	/usr/bin/svn cp --username "$USERNAME" -m "$MESSAGE" "$DOTORG_PLUGIN_URL/trunk" "$DOTORG_PLUGIN_URL/tags/$NEW_VERSION"
	echo -e "Committed and tagged the plugin successfully!\n"
else
	echo -e "Did not commit the changes.\n"
fi

exit 0
