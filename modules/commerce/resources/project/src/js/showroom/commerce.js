import '../../scss/showroom/commerce.scss';
import {
  initCommerce,
} from '../resources/_commerce.js';

const cleanupCommerce = initCommerce(document);

if (import.meta.hot) {
  import.meta.hot.dispose(() => {
    cleanupCommerce();
  });
}

export default function initShowroomCommerce() {}
