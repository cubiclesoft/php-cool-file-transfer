<?php
	// Cool File Transfer receive endpoint.
	// (C) 2017 CubicleSoft.  All Rights Reserved.

	require_once "../base.php";

	// Validate the security token.
	if (CFT_CTstrcmp($row->recvtoken, $_REQUEST["token"]))  CFT_DisplayError("File request token is invalid.", "invalid_token");

	if (isset($_REQUEST["action"]) && $_REQUEST["action"] === "remove")
	{
		// Removing is a matter of deleting the database row.
		try
		{
			$db->Query("DELETE", array($cft_db_files, "WHERE" => "id = ?"), $row->id);
		}
		catch (Exception $e)
		{
			CFT_DisplayError("Unable to remove the file.  A database error occurred.", "db_error");
		}

		// Trigger the notification to the client that the request has been rejected.  This will generally happen immediately and never timeout.
		if ($row->port < 0)
		{
			$fp = @stream_socket_client("tcp://127.0.0.1:" . -$row->port, $errornum, $errorstr, 5, STREAM_CLIENT_CONNECT);
			if ($fp !== false)  @fclose($fp);
		}

		$result = array(
			"success" => true
		);

		CFT_SendResult($result);
	}
	else
	{
		// Check the port number.
		if ($row->port > 0)  CFT_DisplayError("File is already being retrieved.", "in_progress");

		// Verify that the request is active.
		if ($row->lastrequest < time() - 120)  CFT_DisplayError("Sender is no longer connected.", "sender_missing");

		// Start a TCP/IP server on a random port number.
		$serverfp = @stream_socket_server("tcp://127.0.0.1:0", $errornum, $errorstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
		if ($serverfp === false)  CFT_DisplayError("Failed to start local transport interface.", "local_transport_failed");

		$serverinfo = stream_socket_get_name($serverfp, false);
		$pos = strrpos($serverinfo, ":");
		$serverip = substr($serverinfo, 0, $pos);
		$serverport = (int)substr($serverinfo, $pos + 1);

		// Update the database with the new server port.
		try
		{
			$db->Query("UPDATE", array($cft_db_files, array(
				"port" => $serverport,
			), "WHERE" => "id = ? AND port < 1"), $row->id);
		}
		catch (Exception $e)
		{
			CFT_DisplayError("Unable to update the file.  A database error occurred.", "db_error");
		}

		// Trigger the notification to the client that the request has been accepted.  This will generally happen immediately and never timeout.
		if ($row->port < 0)
		{
			$fp = @stream_socket_client("tcp://127.0.0.1:" . -$row->port, $errornum, $errorstr, 5, STREAM_CLIENT_CONNECT);
			if ($fp !== false)  @fclose($fp);
		}

		// Initialize the file download.  Disable automatic gzip compression so that large files can be sent.
		if (function_exists("apache_setenv"))  @apache_setenv("no-gzip", 1);
		@ini_set("zlib.output_compression", 0);
		header("Content-Disposition: attachment; filename=\"" . $row->filename . "\"");
		header("Content-Type: application/octet-stream");
		header("Content-Encoding: none");
		header("Content-Length: " . $row->filesize);
		@ob_flush();
		@flush();

		$lastdbcheck = time();
		$sent = 0;
		while ($sent < $row->filesize)
		{
			// Wait for a connection.
			// I'm aware that this is NOT the correct way to use stream_select().
			// It's for non-blocking use only but stream_socket_accept() doesn't block until there is a connection AND I want the TCP/IP handshake to complete *before* fgets() executes.
			$readfps = array($serverfp);
			$writefps = array();
			$exceptfps = NULL;
			$timeout = $lastdbcheck + 20 - time();
			if ($timeout < 1)  $timeout = 0;
			$timeout++;
			$result = @stream_select($readfps, $writefps, $exceptfps, $timeout);
			if ($result === false)  CFT_DisplayError("Failed to wait for local transport interface.", "local_transport_failed");

			if (count($readfps) && ($fp = @stream_socket_accept($serverfp)) !== false)
			{
				@stream_set_timeout($fp, 5);
				$data = @fgets($fp);

				$data = @json_decode($data, true);

				// There might be a security vulnerability here if the process can be coerced to transfer, for example, /etc/passwd.
				// However, assuming this is an actual issue, then aren't there several far easier ways to obtain said files?
				if (is_array($data) && isset($data["token"]) && is_string($data["token"]) && isset($data["filename"]) && is_string($data["filename"]) && CFT_CTstrcmp($row->recvtoken, $data["token"]) == 0 && file_exists($data["filename"]) && ($fp2 = @fopen($data["filename"], "rb")) !== false)
				{
					if (isset($data["startpos"]) && $data["startpos"] < 0)
					{
						$result = array(
							"success" => false,
							"error" => "Starting position is less than 0.",
							"errorcode" => "invalid_startpos"
						);
					}
					else if (isset($data["startpos"]) && $data["startpos"] > $sent)
					{
						$result = array(
							"success" => false,
							"error" => "Starting position is greater than amount transferred.",
							"errorcode" => "invalid_startpos"
						);
					}
					else
					{
						if (isset($data["startpos"]) && $data["startpos"] < $sent)
						{
							// Seek to the correct starting point in the file.
							$size = @filesize($data["filename"]);
							if ($data["startpos"] + $size < $sent)  fseek($fp2, 0, SEEK_END);
							else  fseek($fp2, $sent - $data["startpos"], SEEK_SET);
						}

						do
						{
							$data2 = @fread($fp2, ($row->filesize - $sent > 65536 ? 65536 : $row->filesize - $sent));
							if ($data2 === false)  $data2 = "";

							echo $data2;
							@ob_flush();
							@flush();

							$sent += strlen($data2);
						} while ($data2 !== "");

						$result = array(
							"success" => true
						);
					}

					fclose($fp2);
				}
				else
				{
					// Wrong/bad connection.  Maybe the client is trying to connect to a reused socket?
					$result = array(
						"success" => false,
						"error" => "Request is for another file transfer in progress.  Was the previous transfer cancelled?",
						"errorcode" => "invalid_input"
					);
				}

				@fwrite($fp, json_encode($result, JSON_UNESCAPED_SLASHES) . "\n");

				fclose($fp);

				$lastdbcheck = time();
			}

			// Check the database every 20 seconds of non-contact for download cancellation.
			if ($lastdbcheck < time() - 20)
			{
				try
				{
					$row = $db->GetRow("SELECT", array(
						"*",
						"FROM" => "?",
						"WHERE" => "id = ?",
					), $cft_db_files, $row->id);
				}
				catch (Exception $e)
				{
					CFT_DisplayError("Unable to retrieve file.  A database error occurred.", "db_error");
				}

				if (!$row)  exit();

				if ($row->lastrequest < time() - 120)  break;

				$lastdbcheck = time();
			}
		}

fwrite($debugfp, time() . " - DONE\n");

		// Remove the file from the transfer list.
		try
		{
			$db->Query("DELETE", array($cft_db_files, "WHERE" => "id = ?"), $row->id);
		}
		catch (Exception $e)
		{
		}
	}
?>