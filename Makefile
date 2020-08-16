SHELL = bash

export

build:
	rm -f quriobot.zip
	zip -r quriobot.zip .

develop:
	mkdir -p ./wp-content/plugins/quriobot-wp
	ln -sf  /opt/quriobot-wp/admin ./wp-content/plugins/quriobot-wp/
	ln -sf  /opt/quriobot-wp/includes ./wp-content/plugins/quriobot-wp/
	ln -sf  /opt/quriobot-wp/readme.txt ./wp-content/plugins/quriobot-wp/
	ln -sf  /opt/quriobot-wp/LICENSE ./wp-content/plugins/quriobot-wp/
	ln -sf  /opt/quriobot-wp/quriobot.php ./wp-content/plugins/quriobot-wp/

.PHONY: build
