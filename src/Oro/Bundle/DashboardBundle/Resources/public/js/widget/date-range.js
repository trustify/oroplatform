define(function(require) {
    'use strict';

    var WidgetConfigDateRangeFilter;
    var DateFilter = require('oro/filter/date-filter');
    var tools = require('oroui/js/tools');

    WidgetConfigDateRangeFilter = DateFilter.extend({
        /**
         * @inheritDoc
         */
        events: {
            'change .date-visual-element': '_onClickUpdateCriteria'
        },

        /**
         * @inheritDoc
         */
        _formatRawValue: function(value) {
            if (value.value && value.value.start) {
                value.value.start = this._toRawValue(value.value.start);

                this._setInputValue(this.criteriaValueSelectors.value.start, value.value.start);
            }
            if (value.value && value.value.end) {
                value.value.end = this._toRawValue(value.value.end);

                this._setInputValue(this.criteriaValueSelectors.value.end, value.value.end);
            }

            return value;
        },

        /**
         * @inheritDoc
         */
        setValue: function(value) {
            var oldValue = this.value;
            this.value = tools.deepClone(value);
            this._onValueUpdated(this.value, oldValue);

            return this;
        }
    });

    return WidgetConfigDateRangeFilter;
});
