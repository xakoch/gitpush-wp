=== GitPush WP ===
Contributors: xakoch
Tags: github, git, push, sync, theme, deploy, development
Requires at least: 5.0
Tested up to: 6.3
Stable tag: 1.0.0
Requires PHP: 7.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sync your WordPress theme files directly with GitHub repository without leaving the admin dashboard.

== Description ==

**GitPush WP** allows you to push changes to your theme files directly to a GitHub repository from the WordPress admin dashboard. This is particularly useful for developers who make changes to theme files directly in WordPress and want to keep their GitHub repository in sync.

### Features:

* Connect to GitHub using personal access token
* Select specific files to sync
* Customize commit messages
* View sync status and history
* No jQuery dependencies - using vanilla JavaScript
* Simple and intuitive interface

### Use cases:

* Quickly push theme changes to GitHub
* Keep your development repository up to date
* No need to use FTP or Git clients
* Perfect for quick fixes and minor updates

== Installation ==

1. Upload the `gitpush-wp` folder to your `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Tools > GitPush WP
4. Enter your GitHub credentials
5. Start syncing!

== Configuration ==

1. Create a GitHub personal access token with 'repo' permissions at GitHub Settings
2. Enter your GitHub username
3. Enter your repository name (without the username)
4. Specify the branch you want to push to (default: main)
5. Save settings

== How to Use ==

1. After saving your GitHub settings, click "Test GitHub Connection"
2. If the connection is successful, your theme files will be loaded
3. Select the files you want to sync using the checkboxes
   - You can use "Select All" or "Select PHP/JS/CSS Files" buttons
4. Enter a commit message
5. Click "Sync Theme to GitHub"
6. View the sync results

== Frequently Asked Questions ==

= Does this plugin require jQuery? =

No, GitPush WP is built with vanilla JavaScript for better performance.

= Can I push to a private repository? =

Yes, as long as you have the proper access token with 'repo' permissions.

= Can I choose which files to sync? =

Yes, you can select individual files, all files, or just specific file types (PHP, JS, CSS).

= Will this plugin work with any theme? =

Yes, it works with any WordPress theme. It accesses the theme directory and lists all available files for syncing.

= Can I use this to sync plugin files? =

Currently, the plugin is designed to sync theme files. Support for plugin files may be added in a future version.

== Screenshots ==

1. Settings screen
2. File selection interface
3. Sync results

== Changelog ==

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.0.0 =
Initial version of GitPush WP.