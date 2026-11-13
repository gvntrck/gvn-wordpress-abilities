<?php
namespace YahnisElsts\PluginUpdateChecker\v6;

require_once dirname( __DIR__ ) . '/plugin-update-checker/Puc/v6p0/PucFactory.php';

class PucFactory extends \YahnisElsts\PluginUpdateChecker\v6p0\PucFactory {
	public static function buildUpdateChecker( $metadataUrl, $fullPath, $slug = '', $checkPeriod = 12, $optionName = '', $muPluginFile = '' ) {
		return new class {
			public function setBranch( $branch ) {}
		};
	}
}
