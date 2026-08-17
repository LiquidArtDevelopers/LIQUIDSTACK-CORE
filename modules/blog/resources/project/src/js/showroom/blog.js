import '../../scss/showroom/blog.scss';
import {
  initModuleBlogFilters01,
} from '../resources/_moduleBlogFilters01.js';
import {
  initBlogCollectionLoader,
} from '../modules/blog/blogCollectionLoader.js';
import {
  initModuleBlogGrid02,
} from '../resources/_moduleBlogGrid02.js';
import {
  initSectionBlogSlider01,
} from '../resources/_sectionBlogSlider01.js';
import {
  initSectionBlogSlider02,
} from '../resources/_sectionBlogSlider02.js';
import {
  initSectionBlogStack01,
} from '../resources/_sectionBlogStack01.js';

const cleanupBlogFilters = initModuleBlogFilters01(document);
const cleanupBlogCollections = initBlogCollectionLoader(document);
const cleanupBlogGrid02 = initModuleBlogGrid02(document);
const cleanupBlogSlider01 = initSectionBlogSlider01(document);
const cleanupBlogSlider02 = initSectionBlogSlider02(document);
const cleanupBlogStack01 = initSectionBlogStack01(document);

if (import.meta.hot) {
  import.meta.hot.dispose(() => {
    cleanupBlogFilters();
    cleanupBlogCollections();
    cleanupBlogGrid02();
    cleanupBlogSlider01();
    cleanupBlogSlider02();
    cleanupBlogStack01();
  });
}

export default function initShowroomBlog() {}
