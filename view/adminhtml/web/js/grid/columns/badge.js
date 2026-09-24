define([
    'Magento_Ui/js/grid/columns/column'
], function (Column) {
    'use strict';

    return Column.extend({
        defaults: {
            bodyTmpl: 'MagoAssistant_Mago/grid/cells/badge',
            badgeColors: {
                'Analytics':     {bg: '#e3f2fd', text: '#1565c0'},
                'Configuration': {bg: '#fce4ec', text: '#c62828'},
                'Content':       {bg: '#f3e5f5', text: '#7b1fa2'},
                'Navigation':    {bg: '#e8f5e9', text: '#2e7d32'},
                'Hosting':       {bg: '#e0f7fa', text: '#00695c'},
                'read':          {bg: '#e8f5e9', text: '#2e7d32'},
                'write':         {bg: '#fff3e0', text: '#e65100'}
            },
            defaultBadge: {bg: '#f5f5f5', text: '#616161'}
        },

        getBadgeStyle: function (record) {
            var value = this.getLabel(record),
                colors = this.badgeColors[value] || this.defaultBadge;

            return 'display:inline-block;padding:3px 10px;border-radius:4px;font-size:12px;font-weight:600;'
                + 'background:' + colors.bg + ';color:' + colors.text + ';';
        }
    });
});
