<?php
	// Add a visually appealing multiple/large file uploader widget.
	// (C) 2017 CubicleSoft.  All Rights Reserved.

	class FlexForms_FileUploader
	{
		public static function Init(&$state, &$options)
		{
			if (!isset($state["modules_file_uploader"]))  $state["modules_file_uploader"] = false;
		}

		public static function FieldType(&$state, $num, &$field, $id)
		{
			if ($field["type"] === "file" && isset($field["uploader"]) && $field["uploader"])
			{
				if ($state["modules_file_uploader"] === false)
				{
					$state["jqueryuiused"] = true;

					$state["css"]["modules-fileuploader"] = array("mode" => "link", "dependency" => false, "src" => $state["supporturl"] . "/fancy-file-uploader/fancy_fileupload.css");
					$state["js"]["modules-fileuploader-base"] = array("mode" => "src", "dependency" => "jqueryui", "src" => $state["supporturl"] . "/fancy-file-uploader/jquery.fileupload.js", "detect" => "jQuery.fn.fileupload");
					$state["js"]["modules-fileuploader-iframe"] = array("mode" => "src", "dependency" => "modules-fileuploader-base", "src" => $state["supporturl"] . "/fancy-file-uploader/jquery.iframe-transport.js", "detect" => "jQuery.fn.FancyFileUpload");
					$state["js"]["modules-fileuploader-fancy"] = array("mode" => "src", "dependency" => "modules-fileuploader-iframe", "src" => $state["supporturl"] . "/fancy-file-uploader/jquery.fancy-fileupload.js", "detect" => "jQuery.fn.FancyFileUpload");

					$state["modules_file_uploader"] = true;
				}

				$options = array(
					"params" => $state["hidden"],
					"fileupload" => array("__flexforms" => true)
				);

				$options["params"]["fileuploader"] = "1";

				if (isset($field["maxchunk"]))  $options["fileupload"]["maxChunkSize"] = floor($field["maxchunk"]);
				else if (!isset($field["maxsize"]))  $options["maxfilesize"] = self::GetMaxUploadFileSize();
				else  $options["maxfilesize"] = floor($field["maxsize"]);

				if (isset($options["maxfilesize"]) && $options["maxfilesize"] >= 1)  $options["fileupload"]["limitMultiFileUploadSize"] = $options["maxfilesize"];

				// Allow the file uploader to be fully customized beyond basic support.
				// Uses dot notation for array key references:  See 'jquery.fancy-fileupload.js' and https://github.com/blueimp/jQuery-File-Upload/wiki/Options
				if (isset($field["uploader_options"]))
				{
					foreach ($field["uploader_options"] as $key => $val)
					{
						$parts = explode(".", $key);

						FlexForms::SetNestedPathValue($options, $parts, $val);
					}
				}

				// Queue up the necessary Javascript for later output.
				ob_start();
?>
jQuery(function() {
	var options = <?php echo json_encode($options, JSON_UNESCAPED_SLASHES); ?>;
<?php
				if (isset($field["uploader_callbacks"]))
				{
					foreach ($field["uploader_callbacks"] as $key => $val)
					{
						$parts = explode(".", $key);

?>
	options<?php foreach ($parts as $part)  echo "['" . $part . "']"; ?> = <?php echo $val; ?>;
<?php
					}
				}
?>

	if (jQuery.fn.FancyFileUpload)
	{
		jQuery('#<?php echo FlexForms::JSSafe($id); ?>').FancyFileUpload(options);
	}
	else
	{
		alert('<?php echo FlexForms::JSSafe(FlexForms::FFTranslate("Warning:  Missing jQuery FancyFileUpload plugin for the file uploader widget.\n\nThis feature requires the FlexForms file-uploader module.")); ?>');
	}
});
<?php
				$state["js"]["modules-fileuploader|" . $id] = array("mode" => "inline", "dependency" => "modules-fileuploader-fancy", "src" => ob_get_contents());
				ob_end_clean();
			}
		}

		public static function GetMaxUploadFileSize()
		{
			$maxpostsize = floor(self::ConvertUserStrToBytes(ini_get("post_max_size")) * 3 / 4);
			if ($maxpostsize > 4096)  $maxpostsize -= 4096;

			$maxuploadsize = self::ConvertUserStrToBytes(ini_get("upload_max_filesize"));
			if ($maxuploadsize < 1)  $maxuploadsize = ($maxpostsize < 1 ? -1 : $maxpostsize);

			return ($maxpostsize < 1 ? $maxuploadsize : min($maxpostsize, $maxuploadsize));
		}

		// Copy included for FlexForms self-containment.
		public static function ConvertUserStrToBytes($str)
		{
			$str = trim($str);
			$num = (double)$str;
			if (strtoupper(substr($str, -1)) == "B")  $str = substr($str, 0, -1);
			switch (strtoupper(substr($str, -1)))
			{
				case "P":  $num *= 1024;
				case "T":  $num *= 1024;
				case "G":  $num *= 1024;
				case "M":  $num *= 1024;
				case "K":  $num *= 1024;
			}

			return $num;
		}

		public static function GetChunkFilename()
		{
			if (isset($_SERVER["HTTP_CONTENT_DISPOSITION"]))
			{
				// Content-Disposition: attachment; filename="urlencodedstr"
				$str = $_SERVER["HTTP_CONTENT_DISPOSITION"];
				if (strtolower(substr($str, 0, 11)) === "attachment;")
				{
					$pos = strpos($str, "\"", 11);
					$pos2 = strrpos($str, "\"");

					if ($pos !== false && $pos2 !== false && $pos < $pos2)
					{
						$str = FlexForms::FilenameSafe(rawurldecode(substr($str, $pos + 1, $pos2 - $pos - 1)));

						if ($str !== "")  return $str;
					}
				}
			}

			return false;
		}

		public static function GetFileStartPosition()
		{
			if (isset($_SERVER["HTTP_CONTENT_RANGE"]) || isset($_SERVER["HTTP_RANGE"]))
			{
				// Content-Range: bytes (*|integer-integer)/(*|integer-integer)
				$vals = explode(" ", preg_replace('/\s+/', " ", str_replace(",", "", (isset($_SERVER["HTTP_CONTENT_RANGE"]) ? $_SERVER["HTTP_CONTENT_RANGE"] : $_SERVER["HTTP_RANGE"]))));
				if (count($vals) === 2 && strtolower($vals[0]) === "bytes")
				{
					$vals = explode("/", trim($vals[1]));
					if (count($vals) === 2)
					{
						$vals = explode("-", trim($vals[0]));

						if (count($vals) === 2)  return (double)$vals[0];
					}
				}
			}

			return 0;
		}
	}

	// Register form handlers.
	if (is_callable("FlexForms::RegisterFormHandler"))
	{
		FlexForms::RegisterFormHandler("init", "FlexForms_FileUploader::Init");
		FlexForms::RegisterFormHandler("field_type", "FlexForms_FileUploader::FieldType");
	}
?>