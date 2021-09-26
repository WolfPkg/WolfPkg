<?php

function check_source() {
	$any = false;
	foreach ($targets as $target) {
		if ($head != $target['published_head']) {
			queue_build($target);
			$any = true;
		}
	}
	return $any;
}

function queue_prebuilds($kind) {
	if (check_source()) {
		return;
	}
	foreach ($targets[$kind] as $target) {
		queue_build($target);
	}
}

switch ($entry) {
case 'check-control':
	$todo = [];
	foreach ($kinds as $kind) {
		if (changed_control($kind)) {
			$todo[] = $kind;
		}
	}
	if ($todo) {
		if (mirror_source()) {
			regen_tarball();
		}
		foreach ($todo as $kind) {
			queue_prebuild($kind);
		}
	}
	break;
case 'nightly':
	if (mirror_source()) {
		regen_tarball();
		check_source();
	}
	break;
case 'push':
	mirror_source();
	if (!check_source()) {
		build_single();
	}
	break;
case 'pr':
	mirror_source();
	build_single();
	break;
case 'dep':
	break;
case 'manual':
	break;
}
