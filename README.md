Cool File Transfer
==================

Directly transfer files between two devices using nothing more than a web browser and a standard PHP enabled web server.  Choose from a MIT or LGPL license.

Instead of using third-party services such as Dropbox or Google Drive to transfer files, Cool File Transfer directly transfers files through a host that both devices have access to.  It's great for moving large files without requiring intermediate storage!

Your PC, tablet, smartphone, refridgerator, and IT department thank you for choosing Cool File Transfer.

[![Awesome demo of Cool File Transfer](https://user-images.githubusercontent.com/1432111/29055577-1d21076c-7bb2-11e7-86bd-a46b825ecf27.png)](https://www.youtube.com/watch?v=haWIVLhefnA "Awesome demo of Cool File Transfer")

Features
--------

* Transfer files of any size.
* Unlimited user ecosystem.
* Beautiful, elegant user interface.
* Easy integration into websites (e.g. Intranets).
* Uploading works on any device with a relatively modern-ish Javascript enabled web browser that has HTML 5 blob support.
* Downloading works on any device/web browser that supports downloading files.
* Can be used as the basis of a script that automatically picks up and processes content streams as they upload but without storing anything to disk.
* Fabulous 2-minute installer.
* Multilingual support.
* Has a liberal open source license.  MIT or LGPL, your choice.
* Designed for relatively painless integration into your environment.
* Sits on GitHub for all of that pull request and issue tracker goodness to easily submit changes and ideas respectively.

Getting Started
---------------

Put the contents of this repository on a web server running PHP.  Then run the `install.php` file using your web browser.  SQLite is a great starting database that sets up quickly but MySQL and Postgres are supported too.  Assuming you have a decent setup, the installer takes only a couple of minutes.

Once Cool File Transfer is installed, secure the installation path and then start using it.  A nifty class that is creatively called `CoolFileTransfer` is inside `cft.php`.  It's designed to be an intermediate, isolated layer between the configuration (`config.php`), the database, and an application (or API).  Also included with Cool File Transfer is [jQuery Fancy File Uploader](https://github.com/cubiclesoft/jquery-fancyfileuploader).  Let's use these two classes to display a file transfer dropzone:

```php
<?php
	require_once "/var/www/cool-file-transfer/cft.php";

	$cft = new CoolFileTransfer();

	$srcuser = "The Webmaster (webmaster@cubiclesoft.com)";

	if (isset($_REQUEST["action"]) && $_REQUEST["action"] === "starttransfer")
	{
		if (!isset($_REQUEST["destuser"]) || !isset($_REQUEST["filename"]) || !isset($_REQUEST["filesize"]))
		{
			$result = array(
				"success" => false,
				"error" => "Missing 'destuser', 'filename', or 'filesize'.",
				"errorcode" => "missing_required_fields"
			);
		}
		else
		{
			$result = $cft->StartSendFile($srcuser, $_REQUEST["destuser"], $_REQUEST["filename"], $_REQUEST["filesize"]);
		}

		header("Content-Type: application/json");

		echo json_encode($result, JSON_UNESCAPED_SLASHES);
	}
	else
	{
		// Obviously, you'll use something more dynamic for production use.
		$destuser = "Bob (bob@forapples.com)";

		require_once "/var/www/cool-file-transfer/support/flex_forms.php";
		require_once "/var/www/cool-file-transfer/support/flex_forms_fileuploader.php";
?>
<div class="fileuploadwrap"><input class="text" type="file" id="uploader" name="file" /></div>

<script type="text/javascript" src="/cool-file-transfer/support/jquery-3.1.1.min.js"></script>

<link rel="stylesheet" href="/cool-file-transfer/support/fancy-file-uploader/fancy_fileupload.css" type="text/css" media="all" />
<script type="text/javascript" src="/cool-file-transfer/support/fancy-file-uploader/jquery.ui.widget.js"></script>
<script type="text/javascript" src="/cool-file-transfer/test/support/fancy-file-uploader/jquery.fileupload.js"></script>
<script type="text/javascript" src="/cool-file-transfer/test/support/fancy-file-uploader/jquery.iframe-transport.js"></script>
<script type="text/javascript" src="/cool-file-transfer/test/support/fancy-file-uploader/jquery.fancy-fileupload.js"></script>

<script type="text/javascript" src="<?php echo $cft->GetDefaultJS(); ?>"></script>

<script type="text/javascript">
jQuery(function() {
	var options = {
		'fileupload' : {
			'maxChunkSize' : <?php echo FlexForms_FileUploader::GetMaxUploadFileSize(); ?>
		},
		'preinit' : CoolFileTransfer.PreInit,
		'startupload' : CoolFileTransfer.StartUpload,
		'uploadcancelled' : CoolFileTransfer.UploadCancelled
	};

	jQuery('#uploader').FancyFileUpload(options);
});
</script>

<script type="text/javascript">
CoolFileTransfer.targeturl = '/yourapp/';

CoolFileTransfer.initrequest = {
	'action' : 'starttransfer',
	'destuser' : '<?php echo FlexForms::JSSafe($destuser); ?>',
	'xsrf_token' : 'your_xsrf_token_here'
};
</script>
<?php
	}
?>
```

Okay, that's half of the equation.  That puts the uploader widget onto a page.  Target users have to be aware that there is a file waiting for them, so the `CoolFileTransfer` class makes it easy to display notifications to users:

```php
	require_once "/var/www/cool-file-transfer/cft.php";

	$cft = new CoolFileTransfer();

	$srcuser = "Bob (bob@forapples.com)";

	// Retrieve the notification list.
	$result = $cft->GetRecvList($srcuser);
	if ($result["success"] && count($result["users"]))
	{
?>
<link rel="stylesheet" href="<?=$cft->GetDefaultCSS()?>" type="text/css" media="all" />
<?php
		$cft->OutputHTMLNotifications($result["users"]);
	}
```

Just put that code somewhere before the closing `body` tag in your HTML and your users will see nice notification boxes whenever a file is ready to transfer.

Options
-------

The default Javascript implementation of Cool File Transfer accepts the following options:

* targeturl - A URL to send a request to in order to initiate a file transfer.  The destination server is expected to return a valid result containing target URLs and tokens from `$cft->StartSendFile()` or a standard error JSON object (Default is '').
* initrequest - A Javascript object that contains additional information to pass to the server during the initial request for a token.  This can be used to pass along information such as the user to send the file to (Default is an empty object).
* langmap - An object containing translation strings.  Support exists for most of the user interface (Default is an empty object).

How it Works
------------

Other than PHP's default behavior for file handling, Cool File Transfer doesn't store file data to disk.  Instead, the sending and receiving endpoints set up and connect to TCP/IP servers at various times to perform the necessary communication between two separate PHP processes.  Most third-party services store a file upload until the recipient comes along and retrieves it.  However, doing that requires storage space, which may or may not be feasible.  Regardless, Cool File Transfer is a very different tool.

The sending side attempts to self-regulate its speed to a bitrate of 1.25 seconds of transferred data per request.  This allows both sides to send and receive data at roughly the same rate during a lengthy transfer process.  Since most web browsers will use keep-alive connections, a little extra overhead is fine.  This approach prevents stalling either side for any significant amount of time.

Fun Facts
---------

This product uses a LOT of existing CubicleSoft software (Admin Pack, FlexForms, FlexForms Modules, CSDB, jQuery Fancy File Uploader, and some previously unpublished code).  Completing the entire project in one weekend would not have been feasible without the existing technology stack.

You read that right:  This entire project was produced in just one weekend.  Fully releasing everything to the Interwebs took another day though, but only because making a video to showcase a project takes time.

Admin Pack/FlexForms Integration
--------------------------------

If you use [Admin Pack](https://github.com/cubiclesoft/admin-pack-with-extras), then you can integrate the first half like this:

<?php
	require_once "/var/www/cool-file-transfer/cft.php";

	$cft = new CoolFileTransfer();

	$srcuser = "The Webmaster (webmaster@cubiclesoft.com)";

	if (isset($_REQUEST["action"]) && $_REQUEST["action"] === "starttransfer")
	{
		if (!isset($_REQUEST["destuser"]) || !isset($_REQUEST["filename"]) || !isset($_REQUEST["filesize"]))
		{
			$result = array(
				"success" => false,
				"error" => "Missing 'destuser', 'filename', or 'filesize'.",
				"errorcode" => "missing_required_fields"
			);
		}
		else
		{
			$result = $cft->StartSendFile($srcuser, $_REQUEST["destuser"], $_REQUEST["filename"], $_REQUEST["filesize"]);
		}

		header("Content-Type: application/json");

		echo json_encode($result, JSON_UNESCAPED_SLASHES);
	}
	else
	{
		// Obviously, you'll use something more dynamic for production use.
		$destuser = "Bob (bob@forapples.com)";

		require_once "support/flex_forms_fileuploader.php";

		$desc = "<br>";

		ob_start();
?>
<script type="text/javascript" src="<?php echo $cft->GetDefaultJS(); ?>"></script>
<script type="text/javascript">
CoolFileTransfer.targeturl = '<?php echo BB_JSSafe(BB_GetRequestURLBase()); ?>';

CoolFileTransfer.initrequest = {
	'action' : 'starttransfer',
	'destuser' : '<?php echo BB_JSSafe($destuser); ?>',
	'sec_t' : '<?php echo BB_CreateSecurityToken("starttransfer"); ?>'
};
</script>
<?php
		$desc .= ob_get_contents();
		ob_end_clean();

		$contentopts = array(
			"desc" => "You are signed in as '" . $srcuser . "'.  Send files to '" . $destuser . "'.",
			"htmldesc" => $desc,
			"fields" => array(
				array(
					"type" => "file",
					"name" => "file",
					"uploader" => true,
					"maxchunk" => FlexForms_FileUploader::GetMaxUploadFileSize(),
					"uploader_callbacks" => array(
						"preinit" => "CoolFileTransfer.PreInit",
						"startupload" => "CoolFileTransfer.StartUpload",
						"uploadcancelled" => "CoolFileTransfer.UploadCancelled"
					)
				)
			)
		);

		BB_GeneratePage("Transfer Files", $menuopts, $contentopts);
	}
```

For [FlexForms Extras](https://github.com/cubiclesoft/php-flexforms-extras), the above will be similar but use the usual `$ff->Generate($contentopts)` method.

To implement the second half in Admin Pack, abusing FlexForms CSS dependency injection via `BB_InjectLayoutHead()` is the easiest way to get Cool File Transfer notifications to appear regardless of where the user is located in the application:

```php
	function BB_InjectLayoutHead()
	{
		global $cft, $srcuser, $bb_flexforms;

		// Menu title underline:  Colors with 60% saturation and 75% brightness generally look good.
?>
<style type="text/css">
#menuwrap .menu .title { border-bottom: 2px solid #C48851; }

#contentwrap.showmenu .cft_notifications_wrap { display: none; }
</style>
<?php

		// Retrieve the notification list.
		$result = $cft->GetRecvList($srcuser);
		if ($result["success"] && count($result["users"]))
		{
			// Add everything to the FlexForms instance by (ab)using CSS options.
			$bb_flexforms->AddCSS("cft-default", array("mode" => "link", "dependency" => false, "src" => $cft->GetDefaultCSS()));

			ob_start();
			$cft->OutputHTMLNotifications($result["users"]);

			$bb_flexforms->AddCSS("cft-notifications", array("mode" => "inline", "dependency" => "cft-default", "src" => ob_get_contents()));
			ob_end_clean();
		}
	}
```
