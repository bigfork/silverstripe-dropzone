/* global window */
import jQuery from 'jquery';
import InitDropzoneField from '../components/DropzoneField/DropzoneField';

jQuery.entwine('ss', ($) => {
  $('.cms .field.dropzonefield').entwine({
    onmatch() {
      const $container = this.find('.js-dropzone');

      if ($container.length) {
        InitDropzoneField(this.get(0));
      }
    }
  });
});
