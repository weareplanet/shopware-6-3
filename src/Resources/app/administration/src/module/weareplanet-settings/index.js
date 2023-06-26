/* global Shopware */

import './extension/sw-plugin';
import './extension/sw-settings-index';
import './page/weareplanet-settings';
import './component/sw-weareplanet-credentials';
import './component/sw-weareplanet-options';
import './component/sw-weareplanet-storefront-options';
import enGB from './snippet/en-GB.json';
import deDE from './snippet/de-DE.json';

const {Module} = Shopware;

Module.register('weareplanet-settings', {
	type: 'plugin',
	name: 'WeArePlanet',
	title: 'weareplanet-settings.general.descriptionTextModule',
	description: 'weareplanet-settings.general.descriptionTextModule',
	color: '#62ff80',
	icon: 'default-action-settings',

	snippets: {
		'de-DE': deDE,
		'en-GB': enGB
	},

	routes: {
		index: {
			component: 'weareplanet-settings',
			path: 'index',
			meta: {
				parentPath: 'sw.settings.index'
			}
		}
	}

});
