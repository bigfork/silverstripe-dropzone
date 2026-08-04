/* global jQuery */
import { initDropzoneField, destroyDropzoneField } from '../components/DropzoneField/DropzoneField';

// CMS forms are frequently loaded, replaced and torn down over AJAX long after DOMContentLoaded has
// fired, so entwine - rather than a one-off scan of the document - is what drives the field here.
// jQuery comes from silverstripe/admin's global rather than an import: this module is only ever
// loaded inside the CMS, and adding jQuery to the bundle would give us a second, unrelated copy
jQuery.entwine('bigfork.dropzone', ($) => {
  $('div.dropzonefield').entwine({
    onmatch() {
      this._super();
      initDropzoneField(this[0]);
    },

    onunmatch() {
      destroyDropzoneField(this[0]);
      this._super();
    }
  });

  // GridFieldEditableColumns renders the bare field with no holder div, so the <td> stands in as
  // the holder: it wraps the .js-dropzone container and receives the hidden value inputs. It's
  // given the dropzonefield class to pick up the CMS styling - being a td, the rule above can
  // never match it
  $('input.dropzonefield.editable-column-field').entwine({
    onmatch() {
      this._super();
      const cell = this[0].closest('td');
      if (cell) {
        cell.classList.add('dropzonefield');
        initDropzoneField(cell);
      }
    },

    onunmatch() {
      const cell = this[0].closest('td');
      if (cell) {
        destroyDropzoneField(cell);
        cell.classList.remove('dropzonefield');
      }
      this._super();
    }
  });
});
