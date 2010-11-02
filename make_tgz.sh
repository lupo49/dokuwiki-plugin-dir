#!/usr/local/bin/bash
#
# Create the .tgz file ../plugin-dir.tgz
#
dokuwiki_root=/usr/local/www/dokuwiki
dokuwiki_plugin_root="${dokuwiki_root}/lib/plugins"
plugin_tgz_file="${dokuwiki_plugin_root}/plugin-dir.tgz"

if [ -f "${plugin_tgz_file}" ] ; then 
  rm -f "{plugin_tgz_file}"
fi
cd "${dokuwiki_plugin_root}" || exit 1

#
# Remove the backup files
#
rm -f dir/.* > /dev/null 2>&1
#
# Create the tar file
#
echo "Creating ${plugin_tgz_file}"
tar --create -z --verbose --exclude 'manager.dat' --file "${plugin_tgz_file}" "./dir"
echo rc=$?
#
# List the contents
#
echo "Contents of ${plugin_tgz_file}:"
tar --list --file "${plugin_tgz_file}"

