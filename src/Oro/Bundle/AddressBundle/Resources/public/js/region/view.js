define([
        'underscore',
        'backbone',
        'jquery.select2',
        'jquery.validate'
], function(_, Backbone) {
    'use strict';

    var $ = Backbone.$;

    /**
     * @export  oro/region/view
     * @class   oro.region.View
     * @extends Backbone.View
     */
    return Backbone.View.extend({
        events: {
            'change': 'selectionChanged'
        },

        /**
         * Constructor
         *
         * @param options {Object}
         */
        initialize: function(options) {
            this.target = $(options.target);
            this.$simpleEl = $(options.simpleEl);

            this.target.closest('.controls').append(this.$simpleEl);
            this.uniform = $('#uniform-' + this.target[0].id);
            this.$simpleEl.attr('type', 'text');

            this.showSelect = options.showSelect;
            this.regionRequired = options.regionRequired;

            this.template = _.template($('#region-chooser-template').html());

            this.displaySelect2(this.showSelect);
            this.target.on('select2-init', _.bind(function() {
                this.displaySelect2(this.showSelect);
            }, this));

            this.listenTo(this.collection, 'reset', this.render);
        },

        /**
         * Show/hide select 2 element
         *
         * @param {Boolean} display
         */
        displaySelect2: function(display) {
            if (display) {
                if (this.regionRequired) {
                    this.addRequiredFlag(this.$simpleEl);
                }
                this.target.select2('container').show();
            } else {
                this.target.select2('container').hide();
                if (this.regionRequired) {
                    this.removeRequiredFlag(this.$simpleEl);
                }
                if (this.target.closest('form').data('validator')) {
                    this.target.validate().hideElementErrors(this.target);
                }
            }
        },

        addRequiredFlag: function(el) {
            var label = this.getInputLabel(el);
            if (!label.hasClass('required')) {
                label
                    .addClass('required')
                    .find('em').html('*');
            }
        },

        removeRequiredFlag: function(el) {
            var label = this.getInputLabel(el);
            if (label.hasClass('required')) {
                label
                    .removeClass('required')
                    .find('em').html('&nbsp;');
            }
        },

        getInputLabel: function(el) {
            return el.parent().parent().find('label');
        },

        /**
         * Trigger change event
         */
        sync: function() {
            if (this.target.val() === '' && this.$el.val() !== '') {
                this.$el.trigger('change');
            }
        },

        /**
         * onChange event listener
         *
         * @param e {Object}
         */
        selectionChanged: function(e) {
            this.$el.trigger('value:changing');
            if ($(e.currentTarget).val()) {
                var countryId = $(e.currentTarget).val();
                this.collection.setCountryId(countryId);
                this.collection.fetch({reset: true});
            } else {
                this.collection.reset([]);
            }
        },

        render: function() {
            if (this.collection.models.length > 0) {
                this.target.show();
                this.displaySelect2(true);
                this.uniform.show();

                this.target.find('option[value!=""]').remove();
                this.target.append(this.template({regions: this.collection.models}));
                this.target.val(this.target.data('selected-data') || '').trigger('change');

                this.$simpleEl.hide();
                this.$simpleEl.val('');
            } else {
                this.target.hide();
                this.target.val('');
                this.displaySelect2(false);
                this.uniform.hide();
                this.$simpleEl.show();
            }
            this.$el.trigger('value:changed');
        }
    });
});
