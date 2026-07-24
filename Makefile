# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Build a signed release tarball for the Nextcloud App Store.
#
#   make appstore
#
# Expects the app-store signing material in ~/.nextcloud/certificates/
# (absence.key + absence.crt, see README-appstore notes). The content
# signature (appinfo/signature.json) is written with `occ integrity:sign-app`
# when a server checkout is available at ../.. and the certificate exists;
# the tarball signature for the upload form is printed at the end.

app_name=absence
version=$(shell sed -n 's:.*<version>\(.*\)</version>.*:\1:p' appinfo/info.xml)
build_dir=$(CURDIR)/build
staging_dir=$(build_dir)/appstore
package=$(build_dir)/$(app_name)-$(version).tar.gz
cert_dir=$(HOME)/.nextcloud/certificates
occ=$(CURDIR)/../../occ

.PHONY: appstore clean

appstore: clean
	mkdir -p $(staging_dir)/$(app_name)
	rsync -a \
		--exclude=/.git \
		--exclude=/.github \
		--exclude=/.gitignore \
		--exclude=/build \
		--exclude=/node_modules \
		--exclude=/src \
		--exclude=/tests \
		--exclude=/vendor \
		--exclude=/vendor-bin \
		--exclude=/screenshots \
		--exclude=/composer.json \
		--exclude=/composer.lock \
		--exclude=/eslint.config.mjs \
		--exclude=/package.json \
		--exclude=/package-lock.json \
		--exclude=/.php-cs-fixer.cache \
		--exclude=/.php-cs-fixer.dist.php \
		--exclude=/vite.config.js \
		--exclude=/stylelint.config.cjs \
		--exclude=/psalm.xml \
		--exclude=/Makefile \
		--exclude=/README.md \
		--exclude=/SPECIFICATION.md \
		./ $(staging_dir)/$(app_name)/
	@if [ -f $(cert_dir)/$(app_name).crt ] && [ -f $(occ) ]; then \
		echo "Signing app content (appinfo/signature.json)…"; \
		php $(occ) integrity:sign-app \
			--privateKey=$(cert_dir)/$(app_name).key \
			--certificate=$(cert_dir)/$(app_name).crt \
			--path=$(staging_dir)/$(app_name); \
	else \
		echo "NOTE: skipping content signing ($(cert_dir)/$(app_name).crt or occ not found)"; \
	fi
	tar -czf $(package) -C $(staging_dir) $(app_name)
	@echo ""
	@echo "Package: $(package)"
	@if [ -f $(cert_dir)/$(app_name).key ]; then \
		echo "Tarball signature for the App Store upload:"; \
		openssl dgst -sha512 -sign $(cert_dir)/$(app_name).key $(package) | openssl base64; \
	else \
		echo "NOTE: no $(cert_dir)/$(app_name).key — cannot compute the upload signature"; \
	fi

clean:
	rm -rf $(build_dir)
