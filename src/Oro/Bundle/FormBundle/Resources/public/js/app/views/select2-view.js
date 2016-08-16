define(function(require) {
    'use strict';

    var Select2View;
    var _ = require('underscore');
    var BaseView = require('oroui/js/app/views/base/view');
    require('jquery.select2');

    Select2View = BaseView.extend({

        /**
         * Use for jQuery select2 plugin initialization
         */
        select2Config: {},

        autoRender: true,

        /**
         * @constructor
         *
         * @param {Object} options
         */
        initialize: function(options) {
            this.select2Config = _.result(options, 'select2Config') || _.extend({}, this.select2Config);
        },

        render: function() {
            this.undelegateEvents();
            this.$el.select2(this.select2Config).trigger('select2-init');
            this.$el.select2('readonly', this.$el.is('[readonly]'));
            if (this.select2Config.className) {
                this.$el.parent().find('.select2-container').addClass(this.select2Config.className);
            }
            if (this.select2Config.onAfterInit) {
                this.select2Config.onAfterInit(this.$el.data('select2'));
            }
            this.delegateEvents();
        },

        dispose: function() {
            this.$el.select2('close').select2('destroy');
        }
    });

    return Select2View;
});
