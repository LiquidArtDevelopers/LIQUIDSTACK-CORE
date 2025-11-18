const nombre_empresa = import.meta.env.VITE_BUSINESS_NAME || "(NOMBRE EMPRESA)";
const direccion = import.meta.env.VITE_BUSINESS_ADRESS || "(DIRECCIÓN EMPRESA)";
const cif = import.meta.env.VITE_BUSINESS_CIF || "(CIF EMPRESA)";
const web = import.meta.env.VITE_BUSINESS_WEB || "(WEB EMPRESA)";
const email_contacto = import.meta.env.VITE_BUSINESS_CONTACT || "(CORREO EMPRESA)";
const DEFAULT_LANG = import.meta.env.LANG_DEFAULT || "es";

//dar valor a la constante html con el string del template

const legales = document.getElementsByClassName("legal");
const bodys = document.getElementsByTagName("body")
for(const legal of legales){
    legal.addEventListener("click", function(){
        for(const body of bodys){
            // obtener el idioma de la url
            //COGEMOS EL IDIOMA DE LA URL ACTUAL
            //Con este código cogemos el idioma de la URL actual del cliente. Da igual si ha cambiado el idioma por ajax o por refresh de la web, en la URL siempre estará el idioma en curso.
            let pathActual = window.location.pathname
            let arrPathActual = pathActual.split("/")
            // console.log(arrPathActual)
            let pathLang = arrPathActual[1]
            if(pathLang =="" || pathLang.length > 2){
                pathLang = DEFAULT_LANG
            }

            // console.log(pathLang)
            //En función del lang, mediante la ruta dinámica, coger el template con los términos en el idioma del lang
            let html = ""
            switch (pathLang){    
                case "eu":
                    html=`
                    <div class="modal_terminos" id="modal">
                        <div class="menucaja">
                            <div id="cerrarModal" class="cookie_close legal_close no_select">
                                <span></span>
                                <span></span>
                            </div>
                        </div>
                        <div class="caja">
                            <h2>Lege oharra</h2>
                            <p>Frantzian informazio gizarteko zerbitzuei aplikagarria den araudia betez —bereziki 2004ko ekainaren 21eko 2004-575 Legea, Ekonomia Digitalaren Konfiantzari buruzkoa (LCEN)— jakinarazten da ${web} webgunea (aurrerantzean, «Webgunea») ${nombre_empresa}ren titulartasunekoa dela, egoitza soziala ${direccion} eta zerga-identifikazio zenbakia (TVA intrakomunitarioa) ${cif} dituela. Lege ohar honek Webgunearen erabilera-baldintzak arautzen ditu.</p>

                            <h3>Aplikatu beharreko legea eta jurisdikzioa</h3>
                            <p>Oro har, ${nombre_empresa}ren eta Webguneko erabiltzaileen arteko harremanak <strong>Frantziako zuzenbidearen</strong> mende egongo dira.</p>
                            <p>Alderdiek klausula hauen interpretazio edo betearazpenetik erator daitezkeen auziak ebazteko Frantziako epaitegi eta auzitegi eskudunen mende jartzen dira. Araudiak derrigorrezko forua ezartzen duen kasuetan (adibidez, kontsumitzaileen arloan), foru hori aplikatuko da.</p>

                            <h3>Erabiltzailearen onarpena</h3>
                            <p>«Erabiltzaile»tzat joko da Webgunera sartzen, nabigatzen edo hura erabiltzen duen pertsona oro. Sarbideak eta nabigazioak Lege Ohar hau onartzea dakarte; ados ez bazaude, ez erabili Webgunea.</p>

                            <h3>Webgunerako sarbidea</h3>
                            <p>Webgunerako sarbidea librea eta doakoa da. <strong>Ez da beharrezkoa erregistratzea edo kontua sortzea</strong>.</p>
                            <p>Webguneak izaera korporatiboa eta informatiboa du. <strong>Ez da kontratazio elektronikorik ez ordainketarik egiten Webgunearen bidez</strong>. Zerbitzu profesionalak (TI, web irtenbideak, SEO/SEM, diseinua eta appak) informazioa trukatu ondoren eta banakako kanalen bidez aurrekontu eta kontratazioaren bitartez kudeatzen dira.</p>

                            <h3>Edukia eta erabilera</h3>
                            <p>Bisita arduraz egin behar da, legea, fede ona eta Lege Ohar hau errespetatuz, baita ${nombre_empresa}ren eta hirugarrenen jabetza intelektual eta industrialeko eskubideak ere.</p>
                            <p>Debekatuta dago edukiak legez kanpoko helburuetarako erabiltzea edo Webgunean edo haren edukietan ${nombre_empresa}ren baimenik gabeko kalteak edo aldaketak eragin ditzaketen jarduerak egitea.</p>
                            <p>${nombre_empresa}k, aurrez jakinarazi gabe, edukiak, zerbitzuak edo haien aurkezpena aldatu, kendu edo gehitu ditzake.</p>

                            <h3>Jabetza intelektuala eta industriala</h3>
                            <p>Webguneko edukiak (testuak, irudiak, kodea, diseinua, etab.) ${nombre_empresa}renak edo hirugarrenen lizentziadunak dira. Debekatuta dago haien erreprodukzioa, banaketa, komunikazio publikoa edo eraldaketa ${nombre_empresa}ren aldez aurretiko eta idatzizko baimenik gabe. Markak eta bereizgarriak haien titularrenak dira.</p>
                            <p>Webgunera sartzeak ez du berekin ekartzen jabetza intelektual edo industrialeko eskubideen lagapenik, uko egiterik edo lizentziarik.</p>

                            <h3>Erantzukizuna eta bermeak</h3>
                            <p>${nombre_empresa}k neurri arrazoizkoak hartzen ditu Webguneak behar bezala funtziona dezan eta osagai kaltegarririk ez egon dadin; hala ere, ez du bermatzen, adibide gisa baina ez mugatzaile gisa:</p>
                            <ul>
                                <li>Edukien eta zerbitzuen jarraitutasuna edo eskuragarritasuna.</li>
                                <li>Akatsik eza edo akatsen berehalako zuzenketa.</li>
                                <li>Birusik edo bestelako elementu kaltegarririk eza.</li>
                                <li>Segurtasun-sistemak urratzen dituztenek eragin ditzaketen kalteak.</li>
                                <li>Erabiltzaileek edukiak legearen eta Lege Ohar honen arabera erabiltzea.</li>
                            </ul>
                            <p>${nombre_empresa}k aldi baterako eten dezake sarbidea mantentze-, konponketa-, eguneratze- edo hobekuntza-lanak direla-eta; ahal denean, aldez aurretik jakinaraziko da.</p>

                            <h3>Estekak (linkak)</h3>
                            <p>Hirugarrenen guneetarako estekak informazio helburuz eskaintzen dira. ${nombre_empresa}k ez du haietako edukien ardurarik hartzen, eta ez du bermatzen eskuragarritasuna edo egiazkotasuna.</p>
                            <p>Hirugarrenek Webgune honetarako estekak sartzeak ez du baimenik esan nahi. Ezingo dira esteka-orrialdeetan legez kanpoko, iraingarri, lizun edo ordena publikoaren aurkako edukiak txertatu.</p>

                            <h3>Baldintzen aldaketa</h3>
                            <p>${nombre_empresa}k eskubidea du Lege Ohar hau osorik edo zatika aldatzeko, aurrez jakinarazi gabe. Erabiltzaileari gomendatzen zaio aldian-aldian berrikustea.</p>

                            <h2 id="polcookies">Cookieak</h2>
                            <p>Webguneak <strong>cookie tekniko eta segurtasunekoak</strong> erabiltzen ditu funtzionamendurako beharrezkoak direnak (adibidez, karga-oreka, hizkuntza-hobespena edo inprimakiaren kudeaketa). <strong>Ez dira cookie analitikoak edo publizitarioak erabiltzen</strong>, aurrez informatu eta baimena eskatu ezean, dagozkion ohar edo konfigurazio-panelaren bidez, Frantziako araudiaren eta CNILen jarraibideen arabera.</p>
                            <p>Erabiltzaileak bere nabigatzailea konfigura dezake cookieak blokeatzeko edo ezabatzeko. Informazio gehiago lortzeko, kontsultatu Cookieen Politika.</p>

                            <h2 id="polprivacidad">Pribatutasun politika</h2>

                            <h3>Datuen babesari buruzko oinarrizko informazioa</h3>
                            <table>
                                <tr>
                                    <td>Arduraduna:</td>
                                    <td>${nombre_empresa}</td>
                                </tr>
                                <tr>
                                    <td>Egoitza soziala:</td>
                                    <td>${direccion}</td>
                                </tr>
                                <tr>
                                    <td>Zerga-identifikazioa (TVA):</td>
                                    <td>${cif}</td>
                                </tr>
                                <tr>
                                    <td>Helburuak:</td>
                                    <td>
                                        <ul>
                                            <li>Harremanetarako inprimakiaren bidez jasotako kontsultei erantzutea.</li>
                                            <li>TI, web, SEO/SEM, diseinu eta app zerbitzuei buruzko aurrekontu- edo informazio-eskaerak kudeatzea.</li>
                                            <li>Kudeaketa administratiboa eta legezko betebeharrak betetzea.</li>
                                            <li>Komunikazio informatibo edo komertzialak bidaltzea <em>aurrez emandako baimenarekin soilik</em>.</li>
                                        </ul>
                                    </td>
                                </tr>
                                <tr>
                                    <td>Legitimazioa:</td>
                                    <td>Interesdunak inprimakia bidaltzean emandako baimena; interesdunaren eskariz egindako aurrekontratu-neurriak (aurrekontuak); Webgunearen segurtasunean eta erreklamazioen aurreko defentsan interes legitimoa; eta legezko betebeharrak betetzea.</td>
                                </tr>
                                <tr>
                                    <td>Hartzaileak:</td>
                                    <td>Ez da daturik lagatzen hirugarrenei, lege-betebeharra den kasuez salbu. Sarbidea izan dezakete ${nombre_empresa}ri zerbitzuak ematen dizkioten hornitzaileek (ostatua, TI euskarria, komunikazioak), tratamenduaren enkargatu gisa, DBEO/RGPD 28. artikuluaren arabera.</td>
                                </tr>
                                <tr>
                                    <td>Eskubideak:</td>
                                    <td>Sarbidea, zuzenketa, ezabaketa, aurkakotasuna, mugaketa, eramangarritasuna eta baimena kentzea; baita Frantziako kontrol-agintaritzaren (CNIL) aurrean erreklamatzea ere.</td>
                                </tr>
                                <tr>
                                    <td>Kontserbazioa:</td>
                                    <td>Eskaria artatzeko behar den denboran eta, oro har, azken interakziotik 12 hilabete arte jarraipenerako; ondoren, lege-epeetan blokeatuta edukiko dira balizko erantzukizunei erantzuteko.</td>
                                </tr>
                                <tr>
                                    <td>Informazio gehigarria:</td>
                                    <td>Ondoren datozen klausulatan dago eskuragarri.</td>
                                </tr>
                            </table>

                            <h3>Zein datu pertsonal biltzen ditugu?</h3>
                            <p>Identifikazio eta kontaktu datuak (izena, abizenak, posta elektronikoa, telefonoa), enpresa eta kargua (hala badagokio), mezuaren edukia eta inprimakiaren funtzionamenduarekin eta segurtasunarekin lotutako metadatu teknikoak (IP, data eta ordua, user-agent).</p>

                            <h3>Zertarako erabiltzen ditugu zure datuak?</h3>
                            <ul>
                                <li>Inprimakiaren bidez jasotako kontsultei eta eskaerei erantzuteko.</li>
                                <li>Eskatutakoan, aurrekontuak edo proposamenak prestatu eta bidaltzeko.</li>
                                <li>Beharrezkoa denean, harreman aurrekontraktuala edo kontraktuala banakako kanalen bidez kudeatzeko.</li>
                                <li>Webgunearen segurtasuna hobetzeko eta iruzurra edo abusua prebenitzeko.</li>
                                <li>Baimen espresua emanez gero soilik, komunikazio informatibo edo komertzialak bidaltzeko (eta edozein unetan ezeztatzeko aukerarekin).</li>
                            </ul>

                            <h3>Tratamenduaren oinarri juridikoa</h3>
                            <ul>
                                <li><strong>Baimena</strong>: inprimakia bidaltzean.</li>
                                <li><strong>Aurrekontratu-neurriak</strong>: interesdunaren eskariz (aurrekontuak, proposamenak).</li>
                                <li><strong>Interes legitimoa</strong>: Webgunearen segurtasuna bermatzea, iruzurra prebenitzea eta erreklamazioen aurrean defentsa.</li>
                                <li><strong>Legezko betebeharra</strong>: agintarien eskariei erantzutea eta araudia betetzea.</li>
                            </ul>

                            <h3>Kontserbazio epeak</h3>
                            <p>Datuak gordeko dira zure eskaera kudeatzen dugun bitartean eta, oro har, azken interakziotik 12 hilabete arte jarraipen komertzialerako. Helburuak bete direnean edo ezabaketa eskatzen baduzu, datuak legeak ezarritako epeetan blokeatuta edukiko dira balizko erantzukizunei erantzuteko.</p>

                            <h3>Hartzaileak eta enkargatuak</h3>
                            <p>${nombre_empresa}ri zerbitzuak ematen dizkioten hornitzaileek (ostatua eta web mantentzea, posta eta komunikazioak, TI euskarria) sarbidea izan dezakete, tratamenduaren enkargatu gisa kontratuta eta segurtasun-neurri egokiak aplikatuta. Ez dira datuak hirugarrenei saltzen.</p>

                            <h3>Nazioarteko transferentziak</h3>
                            <p>Oro har, tratamendua Europako Esparru Ekonomikoaren barruan egiten da. EEEtik kanpoko zerbitzuak erabili behar izanez gero, DBEO/RGPDren 45. eta 46. artikuluetan aurreikusitako berme egokiak (egokitasun-erabakiak eta/edo Klausula Kontraktual Estandarrak) aplikatuko dira.</p>

                            <h3>Erabiltzaileen eskubideak</h3>
                            <p>Zure sarbide-, zuzenketa-, ezabaketa-, aurkakotasun-, tratamenduaren mugaketa- eta eramangarritasun-eskubideak, baita emandako baimena kentzeko eskubidea ere, gure harremanetarako inprimakiaren bidez edo Webgunean argitaratutako kontaktu datuen bidez egikaritu ditzakezu. Halaber, CNILen aurrean erreklamazioa aurkezteko eskubidea duzu.</p>

                            <h3>Datuen zehaztasuna</h3>
                            <p>Erabiltzaileak bermatzen du emandako datuak egiazkoak, zehatzak eta eguneratuak direla, eta edozein aldaketa jakinarazteko konpromisoa hartzen du. ${nombre_empresa}k kudeaketak ukatu edo eten ahal izango ditu datu faltsuak, osatugabeak edo zaharkituak ematen badira.</p>

                            <h3>Adingabeak</h3>
                            <p>Gure zerbitzuak ez daude bereziki adingabeei zuzenduta. 14 urtetik beherakoen eskaerak jasoz gero, ordezkari legalen baimena eskatuko da.</p>

                            <h3>Segurtasun-neurriak</h3>
                            <p>${nombre_empresa}k neurri tekniko eta antolaketa-neurri egokiak aplikatzen ditu datuak galtzearen, erabilera okerraren, baimenik gabeko sarbidearen edo lapurretaren aurka babesteko. Hala ere, ezin da Interneteko segurtasun absolutua bermatu.</p>

                            <h3>Hirugarrenen guneetarako estekak</h3>
                            <p>Esteken bidez kanpoko guneetarantz nabigatzean, gune horien pribatutasun politikek arautuko dute nabigazioa; ${nombre_empresa}k ez du erantzukizunik izango horren gainean.</p>

                            <h3>Cookieen erabilera</h3>
                            <p>Lehenespenez cookie teknikoak soilik erabiltzen ditugu. Etorkizunean cookie ez-teknikoak (analitikoak edo publizitarioak) gehituko bagenitu, aldez aurretik zure baimena eskatuko genuke dagokion banner edo konfigurazio-panelaren bidez, CNILen gomendioei jarraituz.</p>

                            <h3>Pribatutasun politikan aldaketak</h3>
                            <p>Beharrezkoa denean eguneratu ahal izango dugu Pribatutasun Politika hau. Aldian-aldian berrikustea gomendatzen dizugu.</p>
                        </div>
                    </div>
                    `
                    break;
                case "es":
                    html=`
                    <div class="modal_terminos" id="modal">
                        <div class="menucaja">
                            <div id="cerrarModal" class="cookie_close legal_close no_select">
                                <span></span>
                                <span></span>
                            </div>
                        </div>
                        <div class="caja">
                            <h2>Aviso legal</h2>
                            <p>En cumplimiento del deber de información del artículo 10 de la Ley 34/2002, de 11 de julio, de Servicios de la Sociedad de la Información y del Comercio Electrónico (LSSI), se informa de que el sitio web ${web} (en adelante, la “Web”) es titularidad de ${nombre_empresa}, con domicilio social en ${direccion} y NIF ${cif}. El presente Aviso Legal regula las condiciones de uso de la Web.</p>

                            <h3>Ley aplicable y jurisdicción</h3>
                            <p>Con carácter general, las relaciones entre ${nombre_empresa} y las personas usuarias de la Web se someten a la legislación española.</p>
                            <p>Las partes se someten a los Juzgados y Tribunales competentes para resolver cualquier controversia derivada de la interpretación o ejecución de estas condiciones. Cuando la normativa aplicable prevea un fuero imperativo (p. ej., en materia de consumidores), se estará a dicho fuero.</p>

                            <h3>Aceptación de la persona usuaria</h3>
                            <p>Se considera “usuario/a” a toda persona que acceda, navegue o utilice la Web. El acceso y la navegación implican la aceptación de este Aviso Legal. Si no está de acuerdo, debe abstenerse de usar la Web.</p>

                            <h3>Acceso a la Web</h3>
                            <p>El acceso a la Web es libre y gratuito. <strong>No es necesario registrarse ni crear una cuenta</strong>.</p>
                            <p>La Web tiene carácter corporativo e informativo. <strong>No se realiza contratación electrónica ni pagos a través de la Web</strong>. Los servicios profesionales (TI, soluciones web, SEO/SEM, diseño y apps) se presupuestan y contratan por canales individualizados tras el intercambio de información.</p>

                            <h3>Contenido y uso</h3>
                            <p>La visita debe hacerse de forma responsable, conforme a la ley, a la buena fe y a este Aviso Legal, respetando los derechos de propiedad intelectual e industrial de ${nombre_empresa} y de terceros.</p>
                            <p>Queda prohibido el uso de los contenidos con fines ilícitos o que puedan causar daños o alteraciones no consentidas en la Web o en sus contenidos.</p>
                            <p>${nombre_empresa} podrá, sin previo aviso, modificar contenidos, servicios o su presentación.</p>

                            <h3>Propiedad intelectual e industrial</h3>
                            <p>Los contenidos de la Web (textos, imágenes, código, diseño, etc.) son titularidad de ${nombre_empresa} o de terceros con licencia. Queda prohibida su reproducción, distribución, comunicación pública o transformación sin autorización previa y por escrito. Las marcas y signos distintivos son de sus respectivos titulares.</p>
                            <p>El acceso a la Web no implica cesión, renuncia o licencia de derechos de propiedad intelectual o industrial.</p>

                            <h3>Responsabilidad y garantías</h3>
                            <p>${nombre_empresa} adopta medidas razonables para el correcto funcionamiento de la Web y la ausencia de componentes dañinos, pero no garantiza, a título enunciativo y no limitativo:</p>
                            <ul>
                                <li>La continuidad o disponibilidad de los contenidos y servicios.</li>
                                <li>La ausencia de errores o su corrección inmediata.</li>
                                <li>La ausencia de virus u otros elementos lesivos.</li>
                                <li>Los daños causados por quienes vulneren los sistemas de seguridad.</li>
                                <li>El uso que las personas usuarias hagan de los contenidos conforme a la ley y a este Aviso Legal.</li>
                            </ul>
                            <p>${nombre_empresa} podrá suspender temporalmente la accesibilidad por mantenimiento, reparación, actualización o mejora. Siempre que sea posible, se avisará con antelación.</p>

                            <h3>Enlaces (links)</h3>
                            <p>Los enlaces a sitios de terceros se ofrecen a título informativo. ${nombre_empresa} no es responsable de sus contenidos ni garantiza su disponibilidad o veracidad.</p>
                            <p>La inserción por terceros de enlaces a esta Web no implica autorización. No podrán incluirse en páginas de enlace contenidos ilegales, difamatorios, obscenos o contrarios al orden público.</p>

                            <h3>Modificación de las condiciones</h3>
                            <p>${nombre_empresa} se reserva el derecho a modificar, total o parcialmente y sin previo aviso, este Aviso Legal. La persona usuaria debe revisarlo periódicamente.</p>

                            <h2 id="polcookies">Cookies</h2>
                            <p>La Web utiliza <strong>cookies técnicas y de seguridad</strong> necesarias para su funcionamiento (p. ej., equilibrio de carga, preferencia de idioma o gestión del formulario). <strong>No se utilizan cookies analíticas ni publicitarias</strong>, salvo que se informe y solicite el consentimiento previamente mediante el correspondiente aviso o panel de configuración.</p>
                            <p>La persona usuaria puede configurar su navegador para bloquear o eliminar cookies. Para más información, consulte la Política de Cookies.</p>

                            <h2 id="polprivacidad">Política de privacidad</h2>

                            <h3>Información básica sobre Protección de Datos</h3>
                            <table>
                                <tr>
                                    <td>Responsable:</td>
                                    <td>${nombre_empresa}</td>
                                </tr>
                                <tr>
                                    <td>Domicilio social:</td>
                                    <td>${direccion}</td>
                                </tr>
                                <tr>
                                    <td>C.I.F.:</td>
                                    <td>${cif}</td>
                                </tr>
                                <tr>
                                    <td>Finalidad:</td>
                                    <td>
                                        <ul>
                                            <li>Atender las consultas remitidas a través del formulario de contacto.</li>
                                            <li>Gestionar solicitudes de presupuesto o de información sobre servicios de TI, web, SEO/SEM, diseño y apps.</li>
                                            <li>Gestión administrativa y cumplimiento de obligaciones legales.</li>
                                            <li>Envío de comunicaciones informativas o comerciales <em>solo</em> cuando exista consentimiento previo.</li>
                                        </ul>
                                    </td>
                                </tr>
                                <tr>
                                    <td>Legitimación:</td>
                                    <td>Consentimiento de la persona interesada al enviar el formulario; medidas precontractuales a petición del interesado (presupuestos); interés legítimo en la seguridad de la Web y en la defensa frente a reclamaciones; y cumplimiento de obligaciones legales.</td>
                                </tr>
                                <tr>
                                    <td>Destinatarios:</td>
                                    <td>No se ceden datos a terceros salvo obligación legal. Podrán acceder proveedores que prestan servicios a ${nombre_empresa} (alojamiento, soporte TI, comunicaciones), actuando como encargados del tratamiento conforme al art. 28 RGPD.</td>
                                </tr>
                                <tr>
                                    <td>Derechos:</td>
                                    <td>Acceso, rectificación, supresión, oposición, limitación, portabilidad y a retirar el consentimiento, así como a presentar reclamación ante la autoridad de control.</td>
                                </tr>
                                <tr>
                                    <td>Conservación:</td>
                                    <td>Durante el tiempo necesario para atender la solicitud y, por regla general, hasta 12 meses desde la última interacción para su seguimiento; y, posteriormente, bloqueados durante los plazos legales aplicables para atender posibles responsabilidades.</td>
                                </tr>
                                <tr>
                                    <td>Información adicional:</td>
                                    <td>Disponible en las cláusulas que figuran a continuación.</td>
                                </tr>
                            </table>

                            <h3>¿Qué datos personales recopilamos?</h3>
                            <p>Datos identificativos y de contacto (nombre, apellidos, correo electrónico, teléfono), empresa y cargo (si procede), así como el contenido del mensaje y metadatos técnicos (IP, fecha y hora, agente de usuario) vinculados a la seguridad y al funcionamiento del formulario.</p>

                            <h3>¿Para qué usamos sus datos?</h3>
                            <ul>
                                <li>Responder a consultas y solicitudes recibidas a través del formulario.</li>
                                <li>Preparar y remitir presupuestos o propuestas cuando se soliciten.</li>
                                <li>Gestionar la relación precontractual o contractual si llegara a formalizarse por canales individualizados.</li>
                                <li>Mejorar la seguridad y prevenir fraudes o abusos de la Web.</li>
                                <li>Enviar comunicaciones informativas o comerciales únicamente cuando haya dado su consentimiento expreso (con opción de revocarlo en cualquier momento).</li>
                            </ul>

                            <h3>Base jurídica del tratamiento</h3>
                            <ul>
                                <li><strong>Consentimiento</strong> del interesado al enviar el formulario.</li>
                                <li><strong>Medidas precontractuales</strong> a petición del interesado (presupuestos, propuestas).</li>
                                <li><strong>Interés legítimo</strong> en garantizar la seguridad de la Web y prevenir el fraude, y en la defensa ante reclamaciones.</li>
                                <li><strong>Obligación legal</strong> de atender requerimientos de autoridades y normativa aplicable.</li>
                            </ul>

                            <h3>Plazos de conservación</h3>
                            <p>Conservaremos los datos mientras gestionemos su solicitud y, por regla general, hasta 12 meses desde la última interacción para su seguimiento comercial. Finalizados los fines o si solicita la supresión, se bloquearán durante los plazos legalmente exigidos para atender posibles responsabilidades.</p>

                            <h3>Destinatarios y encargados</h3>
                            <p>Podrán acceder a sus datos proveedores que prestan servicios a ${nombre_empresa} (alojamiento y mantenimiento web, correo y comunicaciones, soporte TI), bajo contrato de encargo de tratamiento, aplicando medidas adecuadas de seguridad. No se venden datos a terceros.</p>

                            <h3>Transferencias internacionales</h3>
                            <p>Con carácter general, el tratamiento se realiza dentro del Espacio Económico Europeo. Si fuera necesario utilizar servicios ubicados fuera del EEE, se adoptarían las garantías adecuadas (decisiones de adecuación y/o Cláusulas Contractuales Tipo) conforme a los arts. 45 y 46 RGPD.</p>

                            <h3>Derechos de las personas usuarias</h3>
                            <p>Puede ejercer sus derechos de acceso, rectificación, supresión, oposición, limitación, portabilidad y a retirar el consentimiento en cualquier momento, a través del formulario de contacto o de los datos de contacto publicados en la Web. También tiene derecho a reclamar ante la autoridad de control competente en materia de protección de datos.</p>

                            <h3>Exactitud de los datos</h3>
                            <p>La persona usuaria garantiza que los datos facilitados son veraces, exactos y se encuentran actualizados, y se compromete a comunicar cualquier cambio. ${nombre_empresa} podrá denegar o suspender gestiones si se aportan datos falsos, incompletos o desactualizados.</p>

                            <h3>Menores de edad</h3>
                            <p>Nuestros servicios no se dirigen específicamente a menores. Si se recibieran solicitudes de menores de 14 años, se requerirá el consentimiento de sus representantes legales.</p>

                            <h3>Medidas de seguridad</h3>
                            <p>${nombre_empresa} aplica medidas técnicas y organizativas apropiadas para proteger los datos frente a pérdida, uso indebido, acceso no autorizado o sustracción. No obstante, la seguridad absoluta en Internet no puede garantizarse.</p>

                            <h3>Enlaces a sitios de terceros</h3>
                            <p>Al acceder a sitios externos a través de enlaces, la navegación se regirá por las políticas de privacidad de dichos sitios, quedando ${nombre_empresa} desvinculada de cualquier responsabilidad al respecto.</p>

                            <h3>Uso de cookies</h3>
                            <p>Por defecto solo utilizamos cookies técnicas imprescindibles. Si en el futuro incorporamos cookies no técnicas (analíticas o publicitarias), solicitaremos su consentimiento previo mediante el banner o panel de configuración correspondiente.</p>

                            <h3>Cambios en la política de privacidad</h3>
                            <p>Podremos actualizar esta Política de Privacidad cuando resulte necesario. Le recomendamos revisarla periódicamente.</p>
                        </div>
                    </div>
                    `
                    break;
                case "fr":
                    html=`
                    <div class="modal_terminos" id="modal">
                        <div class="menucaja">
                            <div id="cerrarModal" class="cookie_close legal_close no_select">
                                <span></span>
                                <span></span>
                            </div>
                        </div>
                        <div class="caja">
                            <h2>Mentions légales</h2>
                            <p>Conformément à la réglementation française applicable aux services de la société de l’information —notamment la Loi n° 2004-575 du 21 juin 2004 pour la Confiance dans l’Économie Numérique (LCEN)— il est indiqué que le site ${web} (ci-après, le « Site ») appartient à ${nombre_empresa}, dont le siège social est sis ${direccion} et l’identification fiscale (TVA intracommunautaire) ${cif}. Les présentes Mentions légales régissent les conditions d’utilisation du Site.</p>

                            <h3>Droit applicable et juridiction</h3>
                            <p>De manière générale, les relations entre ${nombre_empresa} et les utilisateurs du Site sont régies par le <strong>droit français</strong>.</p>
                            <p>Les parties se soumettent aux tribunaux français compétents pour toute controverse relative à l’interprétation ou à l’exécution des présentes conditions. Lorsque la réglementation prévoit un for impératif (par exemple en matière de consommateurs), ce for prévaudra.</p>

                            <h3>Acceptation de l’utilisateur</h3>
                            <p>Est considérée comme « utilisateur/trice » toute personne qui accède, navigue ou utilise le Site. L’accès et la navigation impliquent l’acceptation des présentes Mentions légales. Si vous n’êtes pas d’accord, vous devez vous abstenir d’utiliser le Site.</p>

                            <h3>Accès au Site</h3>
                            <p>L’accès au Site est libre et gratuit. <strong>Aucun enregistrement ni création de compte n’est requis</strong>.</p>
                            <p>Le Site a un caractère corporatif et informatif. <strong>Aucune contractualisation électronique ni paiement n’est réalisé via le Site</strong>. Les services professionnels (TI, solutions web, SEO/SEM, design et apps) sont chiffrés et, le cas échéant, contractualisés par des canaux individualisés après échange d’informations.</p>

                            <h3>Contenu et utilisation</h3>
                            <p>La visite doit s’effectuer de manière responsable, conformément à la loi, à la bonne foi et aux présentes Mentions légales, tout en respectant les droits de propriété intellectuelle (et industrielle) de ${nombre_empresa} et des tiers.</p>
                            <p>Il est interdit d’utiliser les contenus à des fins illicites ou de réaliser des actions susceptibles de causer des dommages ou des altérations non autorisées sur le Site ou ses contenus.</p>
                            <p>${nombre_empresa} peut, sans préavis, modifier les contenus, les services ou leur présentation.</p>

                            <h3>Propriété intellectuelle et industrielle</h3>
                            <p>Les contenus du Site (textes, images, code, design, etc.) appartiennent à ${nombre_empresa} ou à des tiers licenciés. Toute reproduction, distribution, communication publique ou transformation est interdite sans autorisation préalable et écrite. Les marques et signes distinctifs appartiennent à leurs titulaires respectifs.</p>
                            <p>L’accès au Site n’implique aucune cession, renonciation ni licence sur des droits de propriété intellectuelle ou industrielle.</p>

                            <h3>Responsabilité et garanties</h3>
                            <p>${nombre_empresa} met en œuvre des mesures raisonnables pour le bon fonctionnement du Site et l’absence de composants nuisibles, mais ne garantit pas, à titre indicatif et non limitatif :</p>
                            <ul>
                                <li>La continuité ou la disponibilité des contenus et services.</li>
                                <li>L’absence d’erreurs ni leur correction immédiate.</li>
                                <li>L’absence de virus ou d’autres éléments préjudiciables.</li>
                                <li>Les dommages causés par des personnes qui porteraient atteinte aux systèmes de sécurité.</li>
                                <li>L’utilisation des contenus par les utilisateurs conformément à la loi et aux présentes Mentions légales.</li>
                            </ul>
                            <p>${nombre_empresa} peut suspendre temporairement l’accessibilité pour maintenance, réparation, mise à jour ou amélioration. Lorsque cela est possible, un préavis sera donné.</p>

                            <h3>Liens (hyperliens)</h3>
                            <p>Les liens vers des sites tiers sont fournis à titre informatif. ${nombre_empresa} n’est pas responsable de leurs contenus et ne garantit ni leur disponibilité ni leur véracité.</p>
                            <p>L’insertion de liens vers ce Site par des tiers n’implique aucune autorisation. Il est interdit d’y associer des contenus illicites, diffamatoires, obscènes ou contraires à l’ordre public.</p>

                            <h3>Modification des conditions</h3>
                            <p>${nombre_empresa} se réserve le droit de modifier, totalement ou partiellement et sans préavis, les présentes Mentions légales. L’utilisateur/trice doit les consulter périodiquement.</p>

                            <h2 id="polcookies">Cookies</h2>
                            <p>Le Site utilise des <strong>cookies techniques et de sécurité</strong> nécessaires à son fonctionnement (par ex. répartition de charge, préférence de langue ou gestion du formulaire). <strong>Aucun cookie analytique ni publicitaire n’est utilisé</strong>, sauf information préalable et recueil de votre consentement via l’avis ou le panneau de configuration correspondant, conformément au droit français et aux lignes directrices de la CNIL.</p>
                            <p>L’utilisateur/trice peut configurer son navigateur pour bloquer ou supprimer les cookies. Pour plus d’informations, veuillez consulter la Politique de cookies.</p>

                            <h2 id="polprivacidad">Politique de confidentialité</h2>

                            <h3>Informations de base sur la protection des données</h3>
                            <table>
                                <tr>
                                    <td>Responsable :</td>
                                    <td>${nombre_empresa}</td>
                                </tr>
                                <tr>
                                    <td>Siège social :</td>
                                    <td>${direccion}</td>
                                </tr>
                                <tr>
                                    <td>Identification fiscale (TVA) :</td>
                                    <td>${cif}</td>
                                </tr>
                                <tr>
                                    <td>Finalités :</td>
                                    <td>
                                        <ul>
                                            <li>Répondre aux demandes envoyées via le formulaire de contact.</li>
                                            <li>Gérer les demandes de devis ou d’informations sur les services TI, web, SEO/SEM, design et apps.</li>
                                            <li>Gestion administrative et respect des obligations légales.</li>
                                            <li>Envoi de communications informatives ou commerciales <em>uniquement</em> en cas de consentement préalable.</li>
                                        </ul>
                                    </td>
                                </tr>
                                <tr>
                                    <td>Base juridique :</td>
                                    <td>Consentement de la personne concernée lors de l’envoi du formulaire ; mesures précontractuelles à la demande de l’intéressé(e) (devis) ; intérêt légitime en matière de sécurité du Site et de défense face aux réclamations ; et respect d’obligations légales.</td>
                                </tr>
                                <tr>
                                    <td>Destinataires :</td>
                                    <td>Aucune cession à des tiers, sauf obligation légale. Des prestataires au service de ${nombre_empresa} (hébergement, support IT, communications) peuvent y accéder comme sous-traitants au sens de l’art. 28 RGPD.</td>
                                </tr>
                                <tr>
                                    <td>Droits :</td>
                                    <td>Accès, rectification, effacement, opposition, limitation, portabilité et retrait du consentement ; ainsi que réclamation auprès de l’autorité de contrôle française (CNIL).</td>
                                </tr>
                                <tr>
                                    <td>Conservation :</td>
                                    <td>Pendant le temps nécessaire au traitement de la demande et, en règle générale, jusqu’à 12 mois après la dernière interaction pour le suivi ; puis, données bloquées pendant les délais légaux applicables afin de répondre à d’éventuelles responsabilités.</td>
                                </tr>
                                <tr>
                                    <td>Informations complémentaires :</td>
                                    <td>Disponibles dans les clauses ci-après.</td>
                                </tr>
                            </table>

                            <h3>Quelles données personnelles collectons-nous ?</h3>
                            <p>Données d’identification et de contact (nom, prénom, e-mail, téléphone), entreprise et poste (le cas échéant), ainsi que le contenu du message et des métadonnées techniques (IP, date et heure, agent utilisateur) liées à la sécurité et au bon fonctionnement du formulaire.</p>

                            <h3>À quelles fins utilisons-nous vos données ?</h3>
                            <ul>
                                <li>Répondre aux questions et demandes reçues via le formulaire.</li>
                                <li>Préparer et envoyer des devis ou propositions lorsque cela est sollicité.</li>
                                <li>Gérer la relation précontractuelle ou contractuelle, le cas échéant, par des canaux individualisés.</li>
                                <li>Améliorer la sécurité et prévenir les fraudes ou abus du Site.</li>
                                <li>Envoyer des communications informatives ou commerciales uniquement en cas de consentement exprès (avec possibilité de le révoquer à tout moment).</li>
                            </ul>

                            <h3>Bases juridiques du traitement</h3>
                            <ul>
                                <li><strong>Consentement</strong> : lors de l’envoi du formulaire.</li>
                                <li><strong>Mesures précontractuelles</strong> : à la demande de l’intéressé(e) (devis, propositions).</li>
                                <li><strong>Intérêt légitime</strong> : assurer la sécurité du Site, prévenir la fraude et se défendre en cas de réclamations.</li>
                                <li><strong>Obligation légale</strong> : répondre aux réquisitions des autorités et respecter la réglementation applicable.</li>
                            </ul>

                            <h3>Durées de conservation</h3>
                            <p>Nous conservons les données le temps de la gestion de votre demande et, en règle générale, jusqu’à 12 mois après la dernière interaction pour le suivi commercial. À l’issue des finalités ou en cas de demande d’effacement, les données seront bloquées pendant les délais légalement exigés pour répondre à d’éventuelles responsabilités.</p>

                            <h3>Destinataires et sous-traitants</h3>
                            <p>Peuvent accéder à vos données des prestataires au service de ${nombre_empresa} (hébergement et maintenance web, messagerie et communications, support IT), sous contrat de sous-traitance et avec des mesures de sécurité appropriées. Aucune vente de données à des tiers.</p>

                            <h3>Transferts internationaux</h3>
                            <p>En principe, le traitement est réalisé au sein de l’Espace Économique Européen. Si l’utilisation de services situés hors EEE s’avérait nécessaire, des garanties appropriées seraient mises en place (décisions d’adéquation et/ou Clauses Contractuelles Types) conformément aux art. 45 et 46 RGPD.</p>

                            <h3>Droits des personnes concernées</h3>
                            <p>Vous pouvez exercer vos droits via le formulaire de contact ou les coordonnées publiées sur le Site : accès, rectification, effacement, opposition, limitation, portabilité et retrait du consentement à tout moment. Vous disposez également du droit d’introduire une réclamation auprès de la CNIL.</p>

                            <h3>Exactitude des données</h3>
                            <p>L’utilisateur/trice garantit que les données fournies sont véridiques, exactes et à jour, et s’engage à signaler toute modification. ${nombre_empresa} pourra refuser ou suspendre des démarches si des données fausses, incomplètes ou obsolètes sont fournies.</p>

                            <h3>Mineurs</h3>
                            <p>Nos services ne s’adressent pas spécifiquement aux mineurs. Si des demandes provenaient de personnes de moins de 14 ans, le consentement de leurs représentants légaux serait requis.</p>

                            <h3>Mesures de sécurité</h3>
                            <p>${nombre_empresa} applique des mesures techniques et organisationnelles appropriées pour protéger les données contre la perte, l’usage abusif, l’accès non autorisé ou le vol. Néanmoins, la sécurité absolue sur Internet ne peut être garantie.</p>

                            <h3>Liens vers des sites tiers</h3>
                            <p>Lorsque vous accédez à des sites externes via des liens, votre navigation est régie par les politiques de confidentialité de ces sites ; ${nombre_empresa} décline toute responsabilité à cet égard.</p>

                            <h3>Utilisation des cookies</h3>
                            <p>Par défaut, nous n’utilisons que des cookies techniques indispensables. Si, à l’avenir, nous ajoutons des cookies non techniques (analytiques ou publicitaires), nous solliciterons votre consentement préalable via la bannière ou le panneau de configuration correspondant, conformément aux recommandations de la CNIL.</p>

                            <h3>Modifications de la politique de confidentialité</h3>
                            <p>Nous pourrons mettre à jour la présente Politique de confidentialité lorsque cela sera nécessaire. Nous vous recommandons de la consulter périodiquement.</p>
                        </div>
                    </div>
                    `
                    break;
                default:
                    html=`
                    <div class="modal_terminos" id="modal">
                        <div class="menucaja">
                            <div id="cerrarModal" class="cookie_close legal_close no_select">
                                <span></span>
                                <span></span>
                            </div>
                        </div>
                        <div class="caja">
                            <h2>Lege oharra</h2>
                            <p>Frantzian informazio gizarteko zerbitzuei aplikagarria den araudia betez —bereziki 2004ko ekainaren 21eko 2004-575 Legea, Ekonomia Digitalaren Konfiantzari buruzkoa (LCEN)— jakinarazten da ${web} webgunea (aurrerantzean, «Webgunea») ${nombre_empresa}ren titulartasunekoa dela, egoitza soziala ${direccion} eta zerga-identifikazio zenbakia (TVA intrakomunitarioa) ${cif} dituela. Lege ohar honek Webgunearen erabilera-baldintzak arautzen ditu.</p>

                            <h3>Aplikatu beharreko legea eta jurisdikzioa</h3>
                            <p>Oro har, ${nombre_empresa}ren eta Webguneko erabiltzaileen arteko harremanak <strong>Frantziako zuzenbidearen</strong> mende egongo dira.</p>
                            <p>Alderdiek klausula hauen interpretazio edo betearazpenetik erator daitezkeen auziak ebazteko Frantziako epaitegi eta auzitegi eskudunen mende jartzen dira. Araudiak derrigorrezko forua ezartzen duen kasuetan (adibidez, kontsumitzaileen arloan), foru hori aplikatuko da.</p>

                            <h3>Erabiltzailearen onarpena</h3>
                            <p>«Erabiltzaile»tzat joko da Webgunera sartzen, nabigatzen edo hura erabiltzen duen pertsona oro. Sarbideak eta nabigazioak Lege Ohar hau onartzea dakarte; ados ez bazaude, ez erabili Webgunea.</p>

                            <h3>Webgunerako sarbidea</h3>
                            <p>Webgunerako sarbidea librea eta doakoa da. <strong>Ez da beharrezkoa erregistratzea edo kontua sortzea</strong>.</p>
                            <p>Webguneak izaera korporatiboa eta informatiboa du. <strong>Ez da kontratazio elektronikorik ez ordainketarik egiten Webgunearen bidez</strong>. Zerbitzu profesionalak (TI, web irtenbideak, SEO/SEM, diseinua eta appak) informazioa trukatu ondoren eta banakako kanalen bidez aurrekontu eta kontratazioaren bitartez kudeatzen dira.</p>

                            <h3>Edukia eta erabilera</h3>
                            <p>Bisita arduraz egin behar da, legea, fede ona eta Lege Ohar hau errespetatuz, baita ${nombre_empresa}ren eta hirugarrenen jabetza intelektual eta industrialeko eskubideak ere.</p>
                            <p>Debekatuta dago edukiak legez kanpoko helburuetarako erabiltzea edo Webgunean edo haren edukietan ${nombre_empresa}ren baimenik gabeko kalteak edo aldaketak eragin ditzaketen jarduerak egitea.</p>
                            <p>${nombre_empresa}k, aurrez jakinarazi gabe, edukiak, zerbitzuak edo haien aurkezpena aldatu, kendu edo gehitu ditzake.</p>

                            <h3>Jabetza intelektuala eta industriala</h3>
                            <p>Webguneko edukiak (testuak, irudiak, kodea, diseinua, etab.) ${nombre_empresa}renak edo hirugarrenen lizentziadunak dira. Debekatuta dago haien erreprodukzioa, banaketa, komunikazio publikoa edo eraldaketa ${nombre_empresa}ren aldez aurretiko eta idatzizko baimenik gabe. Markak eta bereizgarriak haien titularrenak dira.</p>
                            <p>Webgunera sartzeak ez du berekin ekartzen jabetza intelektual edo industrialeko eskubideen lagapenik, uko egiterik edo lizentziarik.</p>

                            <h3>Erantzukizuna eta bermeak</h3>
                            <p>${nombre_empresa}k neurri arrazoizkoak hartzen ditu Webguneak behar bezala funtziona dezan eta osagai kaltegarririk ez egon dadin; hala ere, ez du bermatzen, adibide gisa baina ez mugatzaile gisa:</p>
                            <ul>
                                <li>Edukien eta zerbitzuen jarraitutasuna edo eskuragarritasuna.</li>
                                <li>Akatsik eza edo akatsen berehalako zuzenketa.</li>
                                <li>Birusik edo bestelako elementu kaltegarririk eza.</li>
                                <li>Segurtasun-sistemak urratzen dituztenek eragin ditzaketen kalteak.</li>
                                <li>Erabiltzaileek edukiak legearen eta Lege Ohar honen arabera erabiltzea.</li>
                            </ul>
                            <p>${nombre_empresa}k aldi baterako eten dezake sarbidea mantentze-, konponketa-, eguneratze- edo hobekuntza-lanak direla-eta; ahal denean, aldez aurretik jakinaraziko da.</p>

                            <h3>Estekak (linkak)</h3>
                            <p>Hirugarrenen guneetarako estekak informazio helburuz eskaintzen dira. ${nombre_empresa}k ez du haietako edukien ardurarik hartzen, eta ez du bermatzen eskuragarritasuna edo egiazkotasuna.</p>
                            <p>Hirugarrenek Webgune honetarako estekak sartzeak ez du baimenik esan nahi. Ezingo dira esteka-orrialdeetan legez kanpoko, iraingarri, lizun edo ordena publikoaren aurkako edukiak txertatu.</p>

                            <h3>Baldintzen aldaketa</h3>
                            <p>${nombre_empresa}k eskubidea du Lege Ohar hau osorik edo zatika aldatzeko, aurrez jakinarazi gabe. Erabiltzaileari gomendatzen zaio aldian-aldian berrikustea.</p>

                            <h2 id="polcookies">Cookieak</h2>
                            <p>Webguneak <strong>cookie tekniko eta segurtasunekoak</strong> erabiltzen ditu funtzionamendurako beharrezkoak direnak (adibidez, karga-oreka, hizkuntza-hobespena edo inprimakiaren kudeaketa). <strong>Ez dira cookie analitikoak edo publizitarioak erabiltzen</strong>, aurrez informatu eta baimena eskatu ezean, dagozkion ohar edo konfigurazio-panelaren bidez, Frantziako araudiaren eta CNILen jarraibideen arabera.</p>
                            <p>Erabiltzaileak bere nabigatzailea konfigura dezake cookieak blokeatzeko edo ezabatzeko. Informazio gehiago lortzeko, kontsultatu Cookieen Politika.</p>

                            <h2 id="polprivacidad">Pribatutasun politika</h2>

                            <h3>Datuen babesari buruzko oinarrizko informazioa</h3>
                            <table>
                                <tr>
                                    <td>Arduraduna:</td>
                                    <td>${nombre_empresa}</td>
                                </tr>
                                <tr>
                                    <td>Egoitza soziala:</td>
                                    <td>${direccion}</td>
                                </tr>
                                <tr>
                                    <td>Zerga-identifikazioa (TVA):</td>
                                    <td>${cif}</td>
                                </tr>
                                <tr>
                                    <td>Helburuak:</td>
                                    <td>
                                        <ul>
                                            <li>Harremanetarako inprimakiaren bidez jasotako kontsultei erantzutea.</li>
                                            <li>TI, web, SEO/SEM, diseinu eta app zerbitzuei buruzko aurrekontu- edo informazio-eskaerak kudeatzea.</li>
                                            <li>Kudeaketa administratiboa eta legezko betebeharrak betetzea.</li>
                                            <li>Komunikazio informatibo edo komertzialak bidaltzea <em>aurrez emandako baimenarekin soilik</em>.</li>
                                        </ul>
                                    </td>
                                </tr>
                                <tr>
                                    <td>Legitimazioa:</td>
                                    <td>Interesdunak inprimakia bidaltzean emandako baimena; interesdunaren eskariz egindako aurrekontratu-neurriak (aurrekontuak); Webgunearen segurtasunean eta erreklamazioen aurreko defentsan interes legitimoa; eta legezko betebeharrak betetzea.</td>
                                </tr>
                                <tr>
                                    <td>Hartzaileak:</td>
                                    <td>Ez da daturik lagatzen hirugarrenei, lege-betebeharra den kasuez salbu. Sarbidea izan dezakete ${nombre_empresa}ri zerbitzuak ematen dizkioten hornitzaileek (ostatua, TI euskarria, komunikazioak), tratamenduaren enkargatu gisa, DBEO/RGPD 28. artikuluaren arabera.</td>
                                </tr>
                                <tr>
                                    <td>Eskubideak:</td>
                                    <td>Sarbidea, zuzenketa, ezabaketa, aurkakotasuna, mugaketa, eramangarritasuna eta baimena kentzea; baita Frantziako kontrol-agintaritzaren (CNIL) aurrean erreklamatzea ere.</td>
                                </tr>
                                <tr>
                                    <td>Kontserbazioa:</td>
                                    <td>Eskaria artatzeko behar den denboran eta, oro har, azken interakziotik 12 hilabete arte jarraipenerako; ondoren, lege-epeetan blokeatuta edukiko dira balizko erantzukizunei erantzuteko.</td>
                                </tr>
                                <tr>
                                    <td>Informazio gehigarria:</td>
                                    <td>Ondoren datozen klausulatan dago eskuragarri.</td>
                                </tr>
                            </table>

                            <h3>Zein datu pertsonal biltzen ditugu?</h3>
                            <p>Identifikazio eta kontaktu datuak (izena, abizenak, posta elektronikoa, telefonoa), enpresa eta kargua (hala badagokio), mezuaren edukia eta inprimakiaren funtzionamenduarekin eta segurtasunarekin lotutako metadatu teknikoak (IP, data eta ordua, user-agent).</p>

                            <h3>Zertarako erabiltzen ditugu zure datuak?</h3>
                            <ul>
                                <li>Inprimakiaren bidez jasotako kontsultei eta eskaerei erantzuteko.</li>
                                <li>Eskatutakoan, aurrekontuak edo proposamenak prestatu eta bidaltzeko.</li>
                                <li>Beharrezkoa denean, harreman aurrekontraktuala edo kontraktuala banakako kanalen bidez kudeatzeko.</li>
                                <li>Webgunearen segurtasuna hobetzeko eta iruzurra edo abusua prebenitzeko.</li>
                                <li>Baimen espresua emanez gero soilik, komunikazio informatibo edo komertzialak bidaltzeko (eta edozein unetan ezeztatzeko aukerarekin).</li>
                            </ul>

                            <h3>Tratamenduaren oinarri juridikoa</h3>
                            <ul>
                                <li><strong>Baimena</strong>: inprimakia bidaltzean.</li>
                                <li><strong>Aurrekontratu-neurriak</strong>: interesdunaren eskariz (aurrekontuak, proposamenak).</li>
                                <li><strong>Interes legitimoa</strong>: Webgunearen segurtasuna bermatzea, iruzurra prebenitzea eta erreklamazioen aurrean defentsa.</li>
                                <li><strong>Legezko betebeharra</strong>: agintarien eskariei erantzutea eta araudia betetzea.</li>
                            </ul>

                            <h3>Kontserbazio epeak</h3>
                            <p>Datuak gordeko dira zure eskaera kudeatzen dugun bitartean eta, oro har, azken interakziotik 12 hilabete arte jarraipen komertzialerako. Helburuak bete direnean edo ezabaketa eskatzen baduzu, datuak legeak ezarritako epeetan blokeatuta edukiko dira balizko erantzukizunei erantzuteko.</p>

                            <h3>Hartzaileak eta enkargatuak</h3>
                            <p>${nombre_empresa}ri zerbitzuak ematen dizkioten hornitzaileek (ostatua eta web mantentzea, posta eta komunikazioak, TI euskarria) sarbidea izan dezakete, tratamenduaren enkargatu gisa kontratuta eta segurtasun-neurri egokiak aplikatuta. Ez dira datuak hirugarrenei saltzen.</p>

                            <h3>Nazioarteko transferentziak</h3>
                            <p>Oro har, tratamendua Europako Esparru Ekonomikoaren barruan egiten da. EEEtik kanpoko zerbitzuak erabili behar izanez gero, DBEO/RGPDren 45. eta 46. artikuluetan aurreikusitako berme egokiak (egokitasun-erabakiak eta/edo Klausula Kontraktual Estandarrak) aplikatuko dira.</p>

                            <h3>Erabiltzaileen eskubideak</h3>
                            <p>Zure sarbide-, zuzenketa-, ezabaketa-, aurkakotasun-, tratamenduaren mugaketa- eta eramangarritasun-eskubideak, baita emandako baimena kentzeko eskubidea ere, gure harremanetarako inprimakiaren bidez edo Webgunean argitaratutako kontaktu datuen bidez egikaritu ditzakezu. Halaber, CNILen aurrean erreklamazioa aurkezteko eskubidea duzu.</p>

                            <h3>Datuen zehaztasuna</h3>
                            <p>Erabiltzaileak bermatzen du emandako datuak egiazkoak, zehatzak eta eguneratuak direla, eta edozein aldaketa jakinarazteko konpromisoa hartzen du. ${nombre_empresa}k kudeaketak ukatu edo eten ahal izango ditu datu faltsuak, osatugabeak edo zaharkituak ematen badira.</p>

                            <h3>Adingabeak</h3>
                            <p>Gure zerbitzuak ez daude bereziki adingabeei zuzenduta. 14 urtetik beherakoen eskaerak jasoz gero, ordezkari legalen baimena eskatuko da.</p>

                            <h3>Segurtasun-neurriak</h3>
                            <p>${nombre_empresa}k neurri tekniko eta antolaketa-neurri egokiak aplikatzen ditu datuak galtzearen, erabilera okerraren, baimenik gabeko sarbidearen edo lapurretaren aurka babesteko. Hala ere, ezin da Interneteko segurtasun absolutua bermatu.</p>

                            <h3>Hirugarrenen guneetarako estekak</h3>
                            <p>Esteken bidez kanpoko guneetarantz nabigatzean, gune horien pribatutasun politikek arautuko dute nabigazioa; ${nombre_empresa}k ez du erantzukizunik izango horren gainean.</p>

                            <h3>Cookieen erabilera</h3>
                            <p>Lehenespenez cookie teknikoak soilik erabiltzen ditugu. Etorkizunean cookie ez-teknikoak (analitikoak edo publizitarioak) gehituko bagenitu, aldez aurretik zure baimena eskatuko genuke dagokion banner edo konfigurazio-panelaren bidez, CNILen gomendioei jarraituz.</p>

                            <h3>Pribatutasun politikan aldaketak</h3>
                            <p>Beharrezkoa denean eguneratu ahal izango dugu Pribatutasun Politika hau. Aldian-aldian berrikustea gomendatzen dizugu.</p>
                        </div>
                    </div>

                    `
                    break;
            }
            body.insertAdjacentHTML("beforeend", html)            
            switch(legal.getAttribute("data-tipo")){
                case "cookies":
                    var polcookies = document.getElementById("polcookies");
                    polcookies.scrollIntoView({behavior: 'smooth'}, true)
                    break;
                case "privacidad":
                    var polcookies = document.getElementById("polprivacidad");
                    polcookies.scrollIntoView({behavior: 'smooth'}, true)
                    break;
            }            
        }
    })
}

document.addEventListener("click", function(e){   
    if(e.target.id=="cerrarModal" || e.target.id=="modal"){
        const modal = document.getElementById("modal");
        modal.remove()
        if(document.getElementById("legalcookie")){
            const legalcookie = document.getElementById("legalcookie");
            legalcookie.style.display="inherit";
        }
    } 
    if(e.target.id=="legalcookie"){
        const legalcookie = document.getElementById("legalcookie");
        for(const body of bodys){
            body.insertAdjacentHTML("beforeend", html)
            var polcookies = document.getElementById("polprivacidad");
            polcookies.scrollIntoView({behavior: 'smooth'}, true)
            legalcookie.style.display="none";
        }        
    }
})