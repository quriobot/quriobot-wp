SHELL = bash

export

build:
	rm -f quriobot.zip
	zip -r quriobot.zip .

.PHONY: build
