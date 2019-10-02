<?php
	// Add a visually appealing multiple/large file uploader widget.
	// (C) 2019 CubicleSoft.  All Rights Reserved.

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

		public static function HandleUpload($filekey, $options = array())
		{
			if (isset($_REQUEST["fileuploader"]))
			{
				header("Content-Type: application/json");

				if (isset($options["allowed_exts"]))
				{
					$allowedexts = array();

					if (is_string($options["allowed_exts"]))  $options["allowed_exts"] = explode(",", $options["allowed_exts"]);

					foreach ($options["allowed_exts"] as $ext)
					{
						$ext = strtolower(trim(trim($ext), "."));
						if ($ext !== "")  $allowedexts[$ext] = true;
					}
				}

				$files = FlexForms::NormalizeFiles($filekey);
				if (!isset($files[0]))  $result = array("success" => false, "error" => FlexForms::FFTranslate("File data was submitted but is missing."), "errorcode" => "bad_input");
				else if (!$files[0]["success"])  $result = $files[0];
				else if (isset($options["allowed_exts"]) && !isset($allowedexts[strtolower($files[0]["ext"])]))
				{
					$result = array(
						"success" => false,
						"error" => FlexForms::FFTranslate("Invalid file extension.  Must be one of %s.", "'." . implode("', '.", array_keys($allowedexts)) . "'"),
						"errorcode" => "invalid_file_ext"
					);
				}
				else
				{
					// For chunked file uploads, get the current filename and starting position from the incoming headers.
					$name = self::GetChunkFilename();
					if ($name !== false)
					{
						$startpos = self::GetFileStartPosition();

						$name = substr($name, 0, -(strlen($files[0]["ext"]) + 1));

						if (isset($options["filename_callback"]) && is_callable($options["filename_callback"]))  $filename = call_user_func_array($options["filename_callback"], array($name, strtolower($files[0]["ext"]), $files[0]));
						else if (isset($options["filename"]))  $filename = str_replace(array("{name}", "{ext}"), array($name, strtolower($files[0]["ext"])), $options["filename"]);
						else  $filename = false;

						if (!is_string($filename))  $result = array("success" => false, "error" => FlexForms::FFTranslate("The server did not set a valid filename."), "errorcode" => "invalid_filename");
						else
						{
							if (file_exists($filename) && $startpos === filesize($filename))  $fp = @fopen($filename, "ab");
							else
							{
								$fp = @fopen($filename, ($startpos > 0 ? "r+b" : "wb"));
								if ($fp !== false)  @fseek($fp, $startpos, SEEK_SET);
							}

							$fp2 = @fopen($files[0]["file"], "rb");

							if ($fp === false)  $result = array("success" => false, "error" => FlexForms::FFTranslate("Unable to open a required file for writing."), "errorcode" => "open_failed", "info" => $filename);
							else if ($fp2 === false)  $result = array("success" => false, "error" => FlexForms::FFTranslate("Unable to open a required file for reading."), "errorcode" => "open_failed", "info" => $files[0]["file"]);
							else
							{
								do
								{
									$data2 = @fread($fp2, 10485760);
									if ($data2 === false)  $data2 = "";
									@fwrite($fp, $data2);
								} while ($data2 !== "");

								fclose($fp2);
								fclose($fp);

								$result = array(
									"success" => true
								);
							}
						}
					}
					else
					{
						$name = substr($files[0]["name"], 0, -(strlen($files[0]["ext"]) + 1));

						if (isset($options["filename_callback"]) && is_callable($options["filename_callback"]))  $filename = call_user_func_array($options["filename_callback"], array($name, strtolower($files[0]["ext"]), $files[0]));
						else if (isset($options["filename"]))  $filename = str_replace(array("{name}", "{ext}"), array($name, strtolower($files[0]["ext"])), $options["filename"]);
						else  $filename = false;

						if (!is_string($filename))  $result = array("success" => false, "error" => FlexForms::FFTranslate("The server did not set a valid filename."), "errorcode" => "invalid_filename");
						else
						{
							@copy($files[0]["file"], $filename);

							$result = array(
								"success" => true
							);
						}
					}
				}

				if ($result["success"] && isset($options["result_callback"]) && is_callable($options["result_callback"]))  call_user_func_array($options["result_callback"], array(&$result, $filename, $name, strtolower($files[0]["ext"]), $files[0]));

				echo json_encode($result, JSON_UNESCAPED_SLASHES);
				exit();
			}
		}
	}

	// Register form handlers.
	if (is_callable("FlexForms::RegisterFormHandler"))
	{
		FlexForms::RegisterFormHandler("init", "FlexForms_FileUploader::Init");
		FlexForms::RegisterFormHandler("field_type", "FlexForms_FileUploader::FieldType");
	}
?>