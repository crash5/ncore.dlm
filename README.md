# Synology Download Station nCore search plugin

## QuickStart

Add the **ncore.dlm** in Download Station > Settings > BT Search.

If you get "error 1407" during search: restart DownloadStation in the Package Center.

To login with 2FA, append the 2fa code after the user name seperated with a pipe "|" character (eg: jack|123123), and click on verify on the edit dialog.


## Contributing

- To create package from source run the `./tools/create_package.sh` script from the repo root.
- Cookie file: `/tmp/ncore.cookie`


## Logging

- Log file if ($debug = true): `/tmp/ncore.log`
- To log a message: `$this->log('Message');`
- Watch log: `tail -f /tmp/ncore.log`


### Synology paths:

- Search plugin base (account.php, btsearch.php, common.php): @appstore/DownloadStation/btsearch/
- User plugins: @appconf/DownloadStation/download/userplugins/
