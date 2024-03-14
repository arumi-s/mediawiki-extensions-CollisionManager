<?php

namespace MediaWiki\Extension\CollisionManager;

use MediaWiki\MediaWikiServices;
use ExtensionRegistry;

/**
 * CollisionManager wiring for MediaWiki services.
 */
return [
	'CollisionManager.CollisionStore' => static function (MediaWikiServices $services) {

		return new CollisionStore(
			$services->getMainConfig(),
			$services->getDBLoadBalancer(),
			$services->getMainWANObjectCache(),
			$services->getRedirectLookup(),
			ExtensionRegistry::getInstance()->isLoaded('TextTransform') ? $services->get('TextTransform.CachedTextNormalizer') : null,
			ExtensionRegistry::getInstance()->isLoaded('Disambiguator') ? $services->get('DisambiguatorLookup') : null,
		);
	},
	'CollisionManager.DisambigBuilder' => static function (MediaWikiServices $services) {

		return new DisambigBuilder(
			$services->getMainConfig(),
			$services->getLinkRenderer(),
			$services->get('CollisionManager.CollisionStore')
		);
	},
];
