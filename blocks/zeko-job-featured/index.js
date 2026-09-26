/**
 * Editor script for Zeko Featured Jobs block.
 *
 * @package Zeko_Jobs
 */

(function (wp) {
	const { registerBlockType } = wp.blocks;
	const { TextControl } = wp.components;
	const { __ } = wp.i18n;

	registerBlockType('zeko-jobs/featured', {
		edit: function (props) {
			const { attributes, setAttributes } = props;

			return wp.element.createElement('div', { className: 'zeko-jobs-block-preview' }, [
				wp.element.createElement('p', { key: 'title' }, __('Zeko Featured Jobs Block', 'zeko-jobs')),
				wp.element.createElement(TextControl, {
					key: 'limit',
					label: __('Number of jobs', 'zeko-jobs'),
					value: String(attributes.limit),
					onChange: function (val) {
						setAttributes({ limit: parseInt(val, 10) || 6 });
					},
				}),
			]);
		},
		save: function () {
			return null;
		},
	});
})(window.wp);
