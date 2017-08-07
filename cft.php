<?php
	// Cool File Transfer frontend application class.
	// (C) 2017 CubicleSoft.  All Rights Reserved.

	if (!class_exists("CoolFileTransfer", false))
	{
		class CoolFileTransfer
		{
			private $config, $db, $cft_db_files;

			public function __construct()
			{
				$rootpath = str_replace("\\", "/", dirname(__FILE__));

				require $rootpath . "/config.php";

				$this->config = $config;
				$this->db = false;

				$dbprefix = $this->config["db_table_prefix"];
				$this->cft_db_files = $dbprefix . "files";
			}

			public function StartSendFile($srcuser, $destuser, $filename, $filesize)
			{
				$result = $this->InitDB();
				if (!$result["success"])  return $result;

				// Generate valid tokens.
				if (!class_exists("CSPRNG", false))  require_once $this->config["rootpath"] . "/support/random.php";

				$rng = new CSPRNG();

				$data = array(
					"srcuser" => (string)$srcuser,
					"destuser" => (string)$destuser,
					"sendtoken" => $rng->GenerateString(64),
					"recvtoken" => $rng->GenerateString(64),
					"filename" => trim(str_replace("\"", "", preg_replace('/\s+/', " ", $filename))),
					"filesize" => preg_replace('/[^0-9]/', "", $filesize)
				);

				try
				{
					// Remove old requests.
					$this->db->Query("DELETE", array($this->cft_db_files, "WHERE" => "lastrequest < ?"), time() - 3600);

					// Insert the new file request into the database.
					$this->db->Query("INSERT", array($this->cft_db_files, array(
						"lastrequest" => time(),
						"srcuser" => $data["srcuser"],
						"destuser" => $data["destuser"],
						"sendtoken" => $data["sendtoken"],
						"recvtoken" => $data["recvtoken"],
						"filename" => $data["filename"],
						"filesize" => $data["filesize"],
						"port" => 0
					), "AUTO INCREMENT" => "id"));

					$id = $this->db->GetInsertID();
				}
				catch (Exception $e)
				{
					return array("success" => false, "error" => self::CFT_Translate("Unable to start a new file.  A database error occurred."), "errorcode" => "db_error", "info" => $e->getMessage());
				}

				return array("success" => true, "sendurl" => $this->config["rooturl"] . "send/", "id" => $id, "token" => $data["sendtoken"], "cancelurl" => $this->config["rooturl"] . "send/?id=" . $id . "&token=" . $data["sendtoken"] . "&action=cancel");
			}

			public function GetDefaultCSS()
			{
				return $this->config["rooturl"] . "support/cft.css";
			}

			public function GetRecvList($destuser)
			{
				$result = $this->InitDB();
				if (!$result["success"])  return $result;

				$users = array();

				try
				{
					$result = $this->db->Query("SELECT", array(
						"*",
						"FROM" => "?",
						"WHERE" => "destuser = ? AND lastrequest >= ? AND port < 0",
						"ORDER BY" => "srcuser, filename, filesize DESC",
					), $this->cft_db_files, $destuser, time() - 120);
				}
				catch (Exception $e)
				{
					return array("success" => false, "error" => self::CFT_Translate("Unable to retrieve file transfer request list.  A database error occurred."), "errorcode" => "db_error", "info" => $e->getMessage());
				}

				// Retrieve content so it is broken down by source user.
				$lastuser = false;
				while ($row = $result->NextRow())
				{
					if ($lastuser !== $row->srcuser)
					{
						if ($lastuser !== false)  $users[$lastuser] = $files;

						$files = array();
						$lastuser = $row->srcuser;
					}

					$url = $this->config["rooturl"] . "recv/?id=" . $row->id . "&token=" . $row->recvtoken;

					$files[] = array(
						"filename" => $row->filename,
						"filesize" => $row->filesize,
						"download" => $url,
						"remove" => $url . "&action=remove"
					);
				}

				if ($lastuser !== false)  $users[$lastuser] = $files;

				return array("success" => true, "users" => $users);
			}

			public function OutputHTMLNotifications($users)
			{
?>
<script type="text/javascript">
function CoolFileTransfer_RemoveNotification(obj)
{
	if (jQuery(obj).closest('.cft_files').children().length > 1)
	{
		jQuery(obj).closest('.cft_file').remove();
	}
	else
	{
		jQuery(obj).closest('.cft_user_request').remove();
	}
}
</script>

<iframe class="cft_hidden" name="coolfiletransfer"></iframe>
<div class="cft_notifications_wrap">
<div class="cft_notifications">
<?php

				foreach ($users as $user => $files)
				{
?>
	<div class="cft_user_request">
		<div class="cft_user_name_wrap"><div class="cft_user_name"><?=htmlspecialchars($user)?></div></div>
		<div class="cft_user_send_message"><?=self::CFT_Translate("Wants to send:")?></div>
		<div class="cft_files">
<?php
					foreach ($files as $file)
					{
						$pos = strrpos($file["filename"], ".");
						$ext = ($pos === false ? "" : (string)substr($file["filename"], $pos + 1));

?>
			<div class="cft_file">
				<div class="cft_file_controls"><a class="cft_file_remove" href="<?=htmlspecialchars($file["remove"])?>" target="coolfiletransfer" onclick="CoolFileTransfer_RemoveNotification(this);" title="Reject and remove from list">X</a> <a class="cft_file_download" href="<?=htmlspecialchars($file["download"])?>" target="coolfiletransfer" onclick="CoolFileTransfer_RemoveNotification(this);" title="Start the transfer"><?=htmlspecialchars($file["filename"])?></a></div>
				<div class="cft_file_info"><?php echo self::ConvertBytesToUserStr($file["filesize"]) . ($ext != "" ? " | " . htmlspecialchars(strtoupper($ext)) : ""); ?></div>
			</div>
<?php
					}
?>
		</div>
	</div>
<?php
				}
?>
</div>
</div>
<?php
			}

			public function GetDefaultJS()
			{
				return $this->config["rooturl"] . "support/cft.js";
			}

			private function InitDB()
			{
				if ($this->db !== false)  return array("success" => true);

				// Connect to the database.
				$dbclassname = "CSDB_" . $this->config["db_select"];

				if (!class_exists($dbclassname, false))  require_once $this->config["rootpath"] . "/support/csdb/db_" . $this->config["db_select"] . ".php";

				try
				{
					$db = new $dbclassname($this->config["db_select"] . ":" . $this->config["db_dsn"], ($this->config["db_login"] ? $this->config["db_user"] : false), ($this->config["db_login"] ? $this->config["db_pass"] : false));
					if ($this->config["db_master_dsn"] != "")  $db->SetMaster($this->config["db_select"] . ":" . $this->config["db_master_dsn"], ($this->config["db_login"] ? $this->config["db_master_user"] : false), ($this->config["db_login"] ? $this->config["db_master_pass"] : false));

					$db->Query("USE", $this->config["db_name"]);
				}
				catch (Exception $e)
				{
					return array("success" => false, "error" => self::CFT_Translate("Database connection failed."), "errorcode" => "db_connect_failed", "info" => $e->getMessage());
				}

				$this->db = $db;

				return array("success" => true);
			}

			// Copy included for Cool File Transfer self-containment.
			public static function ConvertBytesToUserStr($num)
			{
				$num = (double)$num;

				if ($num < 0)  return "0 B";
				if ($num < 1024)  return number_format($num, 0) . " B";
				if ($num < 1048576)  return str_replace(".0 ", "", number_format($num / 1024, 1)) . " KB";
				if ($num < 1073741824)  return str_replace(".0 ", "", number_format($num / 1048576, 1)) . " MB";
				if ($num < 1099511627776.0)  return str_replace(".0 ", "", number_format($num / 1073741824.0, 1)) . " GB";
				if ($num < 1125899906842624.0)  return str_replace(".0 ", "", number_format($num / 1099511627776.0, 1)) . " TB";

				return str_replace(".0 ", "", number_format($num / 1125899906842624.0, 1)) . " PB";
			}

			protected static function CFT_Translate()
			{
				$args = func_get_args();
				if (!count($args))  return "";

				return call_user_func_array((defined("CS_TRANSLATE_FUNC") && function_exists(CS_TRANSLATE_FUNC) ? CS_TRANSLATE_FUNC : "sprintf"), $args);
			}
		}
	}
?>