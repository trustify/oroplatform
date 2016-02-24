<a name="module_SelectEditorView"></a>
## SelectEditorView ⇐ <code>[TextEditorView](./text-editor-view.md)</code>
Select cell content editor. The cell value should be a value field.
The grid will render a corresponding label from the `options.choices` map.
The editor will use the same mapping

### Column configuration samples:
``` yml
datagrid:
  {grid-uid}:
    inline_editing:
      enable: true
    # <grid configuration> goes here
    columns:
      # Sample 1. Mapped by frontend type
      {column-name-1}:
        frontend_type: select
        choices: # required
          key-1: First
          key-2: Second
      # Sample 2. Full configuration
      {column-name-2}:
        choices: # required
          key-1: First
          key-2: Second
        inline_editing:
          editor:
            view: oroform/js/app/views/editor/select-editor-view
            view_options:
              placeholder: '<placeholder>'
              css_class_name: '<class-name>'
          validation_rules:
            NotBlank: ~
```

### Options in yml:

Column option name                                  | Description
:---------------------------------------------------|:---------------------------------------
choices                                             | Key-value set of available choices
inline_editing.editor.view_options.placeholder      | Optional. Placeholder translation key for an empty element
inline_editing.editor.view_options.placeholder_raw  | Optional. Raw placeholder value
inline_editing.editor.view_options.css_class_name   | Optional. Additional css class name for editor view DOM el
inline_editing.editor.validation_rules | Optional. Validation rules. See [documentation](https://goo.gl/j9dj4Y)

### Constructor parameters

**Extends:** <code>[TextEditorView](./text-editor-view.md)</code>  

| Param | Type | Description |
| --- | --- | --- |
| options | <code>Object</code> | Options container |
| options.model | <code>Object</code> | Current row model |
| options.fieldName | <code>string</code> | Field name to edit in model |
| options.placeholder | <code>string</code> | Placeholder translation key for an empty element |
| options.placeholder_raw | <code>string</code> | Raw placeholder value. It overrides placeholder translation key |
| options.validationRules | <code>Object</code> | Validation rules. See [documentation here](https://goo.gl/j9dj4Y) |
| options.choices | <code>Object</code> | Key-value set of available choices |


* [SelectEditorView](#module_SelectEditorView) ⇐ <code>[TextEditorView](./text-editor-view.md)</code>
  * [.getSelect2Options()](#module_SelectEditorView#getSelect2Options) ⇒ <code>Object</code>
  * [.getSelect2Data()](#module_SelectEditorView#getSelect2Data) ⇒ <code>Object</code>

<a name="module_SelectEditorView#getSelect2Options"></a>
### selectEditorView.getSelect2Options() ⇒ <code>Object</code>
Prepares and returns Select2 options

**Kind**: instance method of <code>[SelectEditorView](#module_SelectEditorView)</code>  
<a name="module_SelectEditorView#getSelect2Data"></a>
### selectEditorView.getSelect2Data() ⇒ <code>Object</code>
Returns Select2 data from corresponding element

**Kind**: instance method of <code>[SelectEditorView](#module_SelectEditorView)</code>  
