import '../../scss/showroom/forms-interactive.scss';
import initArtAccordion01 from '../resources/_artAccordion01.js';
import initArtAccordion02 from '../resources/_artAccordion02.js';
import initGlobalForm from '../resources/_globalForm.js';
import initModuleFormContact from '../resources/_moduleFormContact.js';
import initModuleFormAuth from '../resources/_moduleFormAuth.js';
import initSectTabs01 from '../resources/_sectTabs01.js';

export default function initShowroomFormsInteractive() {
  initSectTabs01();
  initGlobalForm();
  initModuleFormContact();
  initModuleFormAuth();
  initArtAccordion01();
  initArtAccordion02();
}
