define([
    'jquery',
    'underscore',
    'oroui/js/mediator',
    'oroui/js/app/views/base/view'
], function($, _, mediator, BaseView) {
    'use strict';

    var RoleView;

    /**
     * @export orouser/js/views/role-view
     */
    RoleView = BaseView.extend({
        options: {
            elSelector: '',
            formName: '',
            formSelector: '',
            labelSelector: '',
            privilegesSelector: '',
            appendUsersSelector: '',
            removeUsersSelector: '',
            tokenSelector: ''
        },
        privileges: null,

        $form: null,
        $label: null,
        $privileges: null,
        $appendUsers: null,
        $removeUsers: null,
        $token: null,

        events: {
            'click': 'onSubmit'
        },

        listen: {
            'securityAccessLevelsComponent:link:click mediator': 'onAccessLevelsLinkClicked'
        },

        /**
         * Initialize
         *
         * @param {Object} options
         */
        initialize: function(options) {
            this.options = _.defaults(options || {}, this.options);
            this.$el = $(this.options.elSelector);
            this.$form = $(this.options.formSelector);
            this.$label = $(this.options.labelSelector);
            this.$privileges = $(this.options.privilegesSelector);
            this.$appendUsers = $(this.options.appendUsersSelector);
            this.$removeUsers = $(this.options.removeUsersSelector);
            this.$token = $(this.options.tokenSelector);
            this.privileges = JSON.parse(this.$privileges.val());
        },

        /**
         * @inheritDoc
         */
        dispose: function() {
            if (this.disposed) {
                return;
            }

            delete this.$form;
            delete this.$label;
            delete this.$privileges;
            delete this.$appendUsers;
            delete this.$removeUsers;
            delete this.$token;
            delete this.privileges;

            RoleView.__super__.dispose.call(this);
        },

        /**
         * onSubmit event listener
         */
        onSubmit: function(event) {
            if (this.$label.hasClass('error')) {
                return;
            }
            var $form = this.$form;
            if ($form.data('nohash') && !$form.data('sent')) {
                $form.data('sent', true);
                return;
            }
            if ($form.data('sent')) {
                return;
            }

            $form.data('sent', true);

            var action = $form.attr('action');
            var method = $form.attr('method');
            var url = (typeof action === 'string') ? $.trim(action) : '';
            url = url || window.location.href || '';
            if (url) {
                url += '?input_action=' + $(event.currentTarget).data('action');

                // clean url (don't include hash value)
                url = (url.match(/^([^#]+)/) || [])[1];
            }

            var data = this.getData();
            var dataAction = $(event.target).attr('data-action');
            if (dataAction) {
                data.input_action = dataAction;
            }

            var options = {
                url: url,
                type: method || 'GET',
                data: $.param(data)
            };
            mediator.execute('submitPage', options);
        },

        /**
         * @returns {Object}
         */
        getData: function() {
            var data = {};

            data[this.options.formName + '[label]'] = this.$label.val();
            data[this.options.formName + '[privileges]'] = JSON.stringify(this.privileges);
            data[this.options.formName + '[appendUsers]'] = this.$appendUsers.val();
            data[this.options.formName + '[removeUsers]'] = this.$removeUsers.val();
            data[this.options.formName + '[_token]'] = this.$token.val();

            return data;
        },

        /**
         * onClick event listener
         */
        onAccessLevelsLinkClicked: function(data) {
            if (this.disposed) {
                return;
            }

            $.each(this.privileges, function(scopeName, privileges) {
                $.each(privileges, function(key, privilege) {
                    if (privilege.identity.id === data.identityId) {
                        $.each(privilege.permissions, function(permissionName, permission) {
                            if (permission.name === data.permissionName) {
                                permission.accessLevel = data.accessLevel;
                            }
                        });
                    }
                });
            });
        }
    });

    return RoleView;
});
