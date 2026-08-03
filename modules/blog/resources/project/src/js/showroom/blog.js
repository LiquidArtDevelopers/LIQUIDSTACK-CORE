import '../../scss/showroom/blog.scss';
import {
  initModuleBlogFilters01,
} from '../resources/_moduleBlogFilters01.js';
import {
  initSectionBlogSlider01,
} from '../resources/_sectionBlogSlider01.js';

const cleanupBlogFilters = initModuleBlogFilters01(document);
const cleanupBlogSlider = initSectionBlogSlider01(document);

if (import.meta.hot) {
  import.meta.hot.dispose(() => {
    cleanupBlogFilters();
    cleanupBlogSlider();
  });
}

export default function initShowroomBlog() {}
