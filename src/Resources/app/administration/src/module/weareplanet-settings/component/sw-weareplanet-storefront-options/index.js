/* global Shopware */

import template from './index.html.twig';
import constants from '../../page/weareplanet-settings/configuration-constants'

const {Component, Mixin} = Shopware;

Component.register('sw-weareplanet-storefront-options', {
	template: template,

	name: 'WeArePlanetStorefrontOptions',

	mixins: [
		Mixin.getByName('notification')
	],

	props: {
		actualConfigData: {
			type: Object,
			required: true
		},
		allConfigs: {
			type: Object,
			required: true
		},
		selectedSalesChannelId: {
			required: true
		},
		isLoading: {
			type: Boolean,
			required: true
		}
	},

	data() {
		return {
			...constants
		};
	},

	computed: {
	},

	methods: {
		checkTextFieldInheritance(value) {
			if (typeof value !== 'string') {
				return true;
			}

			return value.length <= 0;
		},

		checkBoolFieldInheritance(value) {
			return typeof value !== 'boolean';
		}
	}
});
