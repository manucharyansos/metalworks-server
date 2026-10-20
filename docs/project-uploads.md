# Project uploads and microphone recording

Project files and new task file revisions allow up to 100 MiB per file. Category formats come from `factory_file_extensions`; an empty list blocks new uploads. Task revisions retain the original file extension and use the same category policy. Cutting metadata is required for actual DXF files in the DXF section, not for other formats added to that section.

The API includes `public/.user.ini` with `upload_max_filesize=100M` and `post_max_size=110M`. PHP CGI/FPM reads per-directory configuration when enabled; its cache can take a few minutes to refresh. If hosting controls these values centrally, set the same values in the hosting PHP settings. A reverse proxy must also permit request bodies of at least 110 MiB. No dependency installation or new migration is required for this change.

File-section deletion permanently removes an empty section and its allowed formats. Existing file bytes, workshop IDs and task history are never cascade-deleted. Non-empty sections return a validation message. A stale legacy upload cannot recreate a deleted section.

Microphone recording preserves every MediaRecorder chunk, including the final `dataavailable` event before `stop`. It validates decoded samples before publishing the file, reports silent/empty/invalid recordings, displays a live input meter and elapsed time, and releases the microphone on stop, cancel and dialog teardown. Playback is available before uploading; recording formats follow the selected section policy. Browser QA uses an actual MediaRecorder with a synthetic microphone and verifies the downloaded audio bytes and decoded signal.

References: https://www.php.net/manual/en/configuration.file.per-user.php and https://www.w3.org/TR/mediastream-recording/ .
