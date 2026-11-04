PHP ?= php
IMAGE ?= e-invoice-validator:local
CONTAINER_TIMEOUT ?= 180
KING_SOURCE ?= $(abspath ../king-ext)
DOCKER_LIMITS = --network none --read-only --cap-drop ALL --security-opt no-new-privileges --memory 768m --cpus 2 --pids-limit 128 --tmpfs /tmp:rw,noexec,nosuid,size=128m,mode=1777

.PHONY: test test-native image test-container lint

check-runtime:
	$(PHP) tools/check-runtime.php

test: check-runtime
	$(PHP) tests/run.php

test-native: check-runtime
	REQUIRE_KING=1 "$(KING_SOURCE)/bin/king-xslt-php" tests/run.php

image:
	KING_SOURCE="$(KING_SOURCE)" IMAGE="$(IMAGE)" bash tools/build-runtime.sh

test-container:
	timeout --foreground $(CONTAINER_TIMEOUT)s docker run --rm $(DOCKER_LIMITS) -e REQUIRE_KING=1 --entrypoint php "$(IMAGE)" /opt/validator/tests/run.php

lint:
	find src bin tests examples -type f \( -name '*.php' -o -name e-invoice-validator \) -exec $(PHP) -l {} \;
	bash -n tools/build-runtime.sh

.PHONY: check-runtime
