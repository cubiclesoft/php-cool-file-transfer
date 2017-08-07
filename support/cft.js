// Cool File Transfer
// (C) 2017 CubicleSoft.  All Rights Reserved.

window.CoolFileTransfer = window.CoolFileTransfer || {
	'targeturl' : '',
	'initrequest' : {},
	'langmap' : {},

	Translate : function(str) {
		return (CoolFileTransfer.langmap[str] ? CoolFileTransfer.langmap[str] : str);
	},

	EscapeHTML : function(text) {
		var map = {
			'&': '&amp;',
			'<': '&lt;',
			'>': '&gt;',
			'"': '&quot;',
			"'": '&#039;'
		};

		return text.replace(/[&<>"']/g, function(m) { return map[m]; });
	},

	FormatStr : function(format) {
		var args = Array.prototype.slice.call(arguments, 1);

		return format.replace(/{(\d+)}/g, function(match, number) {
			return (typeof args[number] != 'undefined' ? args[number] : match);
		});
	},

	MaxChunkSize : function(options) {
		if (options._progress.loaded === 0)  return 65536;

		var size = Math.floor(options._progress.bitrate * 1.25 / 8);

		if (size < 65536)  size = 65536;

		return Math.min(options.origMaxChunkSize, size);
	},

	PreInit : function(settings) {
		// Alter the max chunk size option to use dynamic adaptation based on the bit rate.
		settings.fileupload.origMaxChunkSize = settings.fileupload.maxChunkSize;
		settings.fileupload.maxChunkSize = CoolFileTransfer.MaxChunkSize;
	},

	StartUpload : function(SubmitUpload, e, data) {
		var $this = this;

		$this.find('.ff_fileupload_fileinfo').text(data.ff_info.displayfilesize + ' | ' + CoolFileTransfer.Translate('Initializing...'));

		var failed = function(result) {
			if (typeof(result.error) !== 'string')  result.error = CoolFileTransfer.Translate('The server indicated that the upload was not able to be started.  No additional information is available.');
			if (typeof(result.errorcode) !== 'string')  result.errorcode = 'server_response';

			data.ff_info.errors.push(CoolFileTransfer.FormatStr(CoolFileTransfer.Translate('The upload failed.  {0} ({1})'), CoolFileTransfer.EscapeHTML(data.result.error), CoolFileTransfer.EscapeHTML(data.result.errorcode)));

			this.find('.ff_fileupload_errors').html(data.ff_info.errors.join('<br>')).removeClass('ff_fileupload_hidden');

			this.removeClass('ff_fileupload_starting');

			// Hide the progress bar.
			this.find('.ff_fileupload_progress_background').addClass('ff_fileupload_hidden');

			// Alter remove buttons.
			this.find('button.ff_fileupload_remove_file').attr('aria-label', CoolFileTransfer.Translate('Remove from list'));
		};

		data.cft_info = {
			'retries' : 5
		};

		// Make an initial request to start a file transfer.
		var options = {
			'url' : CoolFileTransfer.targeturl,
			'data' : jQuery.extend({}, CoolFileTransfer.initrequest, {
				'filename' : data.files[0].uploadName || data.files[0].name,
				'filesize' : data.files[0].size
			}),
			'dataType' : 'json',
			'success' : function(result) {
				if (data.cft_info)
				{
					if (data.cft_info.ajax)  delete data.cft_info.ajax;

					if (!result.success)  failed.call($this, result);
					else
					{
						// Request approved.  Set up the required parameters.
						data.url = result.sendurl;

						data.formData = {
							'id' : result.id,
							'token' : result.token
						};

						// For later use if either side decides to cancel the upload.
						data.cft_info = {
							'id' : result.id,
							'token' : result.token,
							'cancelurl' : result.cancelurl
						};

						$this.find('.ff_fileupload_fileinfo').text(data.ff_info.displayfilesize + ' | ' + CoolFileTransfer.Translate('Waiting for recipient...'));

						// Wait for the request to be accepted by the destination user.
						var options2 = {
							'url' : data.url,
							'data' : data.formData,
							'dataType' : 'json',
							'success' : function(result2) {
								if (data.cft_info)
								{
									// User has accepted the file upload.
									if (data.cft_info.ajax)  delete data.cft_info.ajax;

									if (!result2.success)  failed.call($this, result2);
									else
									{
										// Submit the upload.
										SubmitUpload();
									}

									delete data.cft_info;
								}
							},
							'error' : function() {
								if (data.cft_info && data.cft_info.retries > 1)
								{
									data.cft_info.retries--;

									setTimeout(function() { if (data.cft_info)  data.cft_info.ajax = jQuery.ajax(options2); }, 1000);
								}
								else if (data.ff_info)
								{
									$this.find('.ff_fileupload_fileinfo').text(data.ff_info.displayfilesize + ' | ' + CoolFileTransfer.Translate('Failed'));

									var result2 = {
										'success' : false,
										'error' : CoolFileTransfer.Translate('A permanent network or data error occurred.')
									};

									failed.call($this, result2);
								}
							}
						};

						data.cft_info.ajax = jQuery.ajax(options2);
					}
				}
			},
			'error' : function() {
				if (data.cft_info && data.cft_info.retries > 1)
				{
					data.cft_info.retries--;

					setTimeout(function() { if (data.cft_info)  data.cft_info.ajax = jQuery.ajax(options); }, 1000);
				}
				else if (data.ff_info)
				{
					$this.find('.ff_fileupload_fileinfo').text(data.ff_info.displayfilesize + ' | ' + CoolFileTransfer.Translate('Failed'));

					var result = {
						'success' : false,
						'error' : CoolFileTransfer.Translate('A permanent network or data error occurred.')
					};

					failed.call($this, result);
				}
			}
		};

		data.cft_info.ajax = jQuery.ajax(options);
	},

	UploadCancelled : function(e, data) {
		if (data.cft_info)
		{
			data.cft_info.retries = 0;
			if (data.cft_info.ajax)  data.cft_info.ajax.abort();

			// Trigger a send to the server to remove the record.
			// There's no particular reason to care about the server's response.
			if (data.cft_info.cancelurl)  jQuery.ajax({ 'url' : data.cft_info.cancelurl });

			delete data.cft_info;
		}
	}
};
