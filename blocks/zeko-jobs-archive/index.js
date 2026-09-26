/**
 * Editor script for Zeko Jobs Archive block.
 *
 * @package Zeko_Jobs
 */

(function (wp) {
	const { registerBlockType } = wp.blocks;
	const { TextControl, ToggleControl } = wp.components;
	const { __ } = wp.i18n;

	registerBlockType('zeko-jobs/archive', {
		edit: function (props) {
			const { attributes, setAttributes } = props;

			return wp.element.createElement('div', { className: 'zeko-jobs-block-preview' }, [
				wp.element.createElement('p', { key: 'title' }, __('Zeko Jobs Archive Block', 'zeko-jobs')),
				wp.element.createElement(TextControl, {
					key: 'limit',
					label: __('Jobs per page', 'zeko-jobs'),
					value: String(attributes.limit),
					onChange: function (val) {
						setAttributes({ limit: parseInt(val, 10) || 10 });
					},
				}),
				wp.element.createElement(ToggleControl, {
					key: 'showFilters',
					label: __('Show filters', 'zeko-jobs'),
					checked: !!attributes.showFilters,
					onChange: function (val) {
						setAttributes({ showFilters: val });
					},
				}),
				wp.element.createElement(ToggleControl, {
					key: 'showSearch',
					label: __('Show search', 'zeko-jobs'),
					checked: !!attributes.showSearch,
					onChange: function (val) {
						setAttributes({ showSearch: val });
					},
				}),
			]);
		},
		save: function () {
			return null;
		},
	});
})(window.wp);
