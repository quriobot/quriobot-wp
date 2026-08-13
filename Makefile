SHELL = bash

export

# The shipped file set has exactly one source of truth: .distignore.
# A plain "zip -r ." here would package mysql_data/ (which contains MySQL's
# generated private keys), wp-content/ and the whole test harness.
dist:
	rm -rf .dist
	mkdir -p .dist/quriobot
	rsync -a --exclude-from=.distignore ./ .dist/quriobot/

build: dist
	rm -f quriobot.zip
	cd .dist && zip -qr ../quriobot.zip quriobot
	unzip -l quriobot.zip

test:
	./tests/run.sh

check:
	./tests/check-version.sh

# Symlinks the shipped entries into the dev WordPress. The list comes from the
# built dist tree, so a new top-level file cannot be missing from the dev install.
develop: dist
	mkdir -p ./wp-content/plugins/quriobot-wp
	ls .dist/quriobot | xargs -I{} ln -sf /opt/quriobot-wp/{} ./wp-content/plugins/quriobot-wp/

.PHONY: dist build develop test check
