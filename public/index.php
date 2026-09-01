<?php
declare(strict_types=1);
session_name('grandprix_public');
if(session_status()!==PHP_SESSION_ACTIVE)session_start(['cookie_httponly'=>true,'cookie_samesite'=>'Lax','cookie_secure'=>(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')]);
if(empty($_SESSION['gp_public_csrf']))$_SESSION['gp_public_csrf']=bin2hex(random_bytes(24));
$csrf=(string)$_SESSION['gp_public_csrf'];
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#071d35">
<title>GRANDPRIX · Financiamiento de motos</title>
<meta name="description" content="GRANDPRIX facilita el acceso al financiamiento de motos con un proceso digital, claro y acompañado. Consulta modelos y solicita en línea.">
<link rel="icon" href="assets/grandprix-symbol.svg" type="image/svg+xml">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="assets/site.css?v=19.0.0">
</head>
<body>
<div class="top-strip">
  <span><i class="fa-solid fa-shield-halved"></i> Confianza y respaldo</span>
  <span><i class="fa-solid fa-motorcycle"></i> Pasión por las motos</span>
  <span><i class="fa-solid fa-circle-check"></i> Proceso simple y transparente</span>
</div>
<header class="site-head" id="siteHead">
  <a class="site-brand" href="#inicio" aria-label="GRANDPRIX inicio"><img src="assets/grandprix-logo-dark.svg" alt="GRANDPRIX Financiamiento de motos"></a>
  <nav id="mainNav">
    <a href="#modelos">Modelos</a>
    <a href="#financiamiento">Financiamiento</a>
    <a href="#proceso">Cómo funciona</a>
    <a href="#nosotros">Nosotros</a>
    <a href="#seguimiento">Seguimiento</a>
  </nav>
  <div class="head-actions">
    <a class="wa-link" href="https://wa.me/584168675230" target="_blank" rel="noopener"><i class="fa-brands fa-whatsapp"></i><span>WhatsApp</span></a>
    <button class="nav-apply" data-open-apply><span>Solicitar financiamiento</span><i class="fa-solid fa-arrow-right"></i></button>
    <button class="mobile-menu" id="mobileMenu" aria-label="Abrir menú"><i class="fa-solid fa-bars"></i></button>
  </div>
</header>

<main>
  <section class="hero shell" id="inicio">
    <div class="hero-copy">
      <span class="hero-badge">FINANCIAMIENTO SIMPLE, RÁPIDO Y SEGURO</span>
      <h1>La forma más clara y moderna de <em>financiar tu moto.</em></h1>
      <p>Explora el catálogo real de GRANDPRIX, conoce cómo funciona el financiamiento y completa tu solicitud digital con seguimiento por etapas: perfil, documentos, visita y cita en oficina.</p>
      <div class="hero-actions">
        <button class="primary" data-open-apply>Solicitar financiamiento <i class="fa-solid fa-arrow-right"></i></button>
        <a class="secondary" href="#modelos"><i class="fa-solid fa-motorcycle"></i> Ver modelos</a>
      </div>
    </div>
    <div class="hero-visual" aria-label="Motocicleta GRANDPRIX">
      <div class="hero-watermark" aria-hidden="true"><i></i><i></i><i></i></div>
      <img src="assets/moto-black.png" alt="Motocicleta disponible para solicitud GRANDPRIX">
    </div>
  </section>

  <section class="benefit-strip shell" aria-label="Beneficios GRANDPRIX">
    <article><i class="fa-solid fa-motorcycle"></i><span><b>Catálogo real</b><small>Modelos de nuestra cartera</small></span></article>
    <article><i class="fa-solid fa-hand-holding-dollar"></i><span><b>Financiamiento</b><small>Proceso con respaldo</small></span></article>
    <article><i class="fa-solid fa-shield-halved"></i><span><b>Proceso digital</b><small>Claro y guiado</small></span></article>
    <article><i class="fa-solid fa-user-check"></i><span><b>Acompañamiento</b><small>En cada etapa</small></span></article>
    <article><i class="fa-solid fa-location-dot"></i><span><b>Maracaibo</b><small>Atención presencial</small></span></article>
  </section>

  <section class="section shell" id="modelos">
    <div class="section-head proposal-head">
      <div>
        <span class="eyebrow">EXPLORA NUESTROS MODELOS</span>
        <h2>Encuentra la moto ideal para ti</h2>
        <p>El catálogo se construye desde los modelos presentes en la cartera real de GRANDPRIX. La disponibilidad final se confirma durante la evaluación.</p>
      </div>
      <div class="section-actions">
        <span class="mini-badge" id="catalogCountBadge"><i class="fa-solid fa-layer-group"></i> Cargando catálogo…</span>
        <button class="ghost" data-open-apply>Solicitar una moto</button>
      </div>
    </div>
    <div class="catalog-toolbar">
      <label class="catalog-search"><i class="fa-solid fa-magnifying-glass"></i><input id="catalogSearch" placeholder="Buscar modelo..."></label>
      <div class="catalog-view-actions">
        <button class="view-toggle active" data-catalog-view="grid" aria-label="Vista de tarjetas"><i class="fa-solid fa-table-cells-large"></i></button>
        <button class="view-toggle" data-catalog-view="compact" aria-label="Vista compacta"><i class="fa-solid fa-bars"></i></button>
      </div>
    </div>
    <div class="catalog-stats" id="catalogStats">
      <div class="stat loading"><span></span></div><div class="stat loading"><span></span></div><div class="stat loading"><span></span></div>
    </div>
    <div id="catalogGrid" class="catalog-grid"><div class="loading-card"><span></span><b>Cargando modelos reales…</b></div></div>
  </section>

  <section class="section shell financing-section" id="financiamiento">
    <div class="section-head compact proposal-head">
      <div>
        <span class="eyebrow">FINANCIAMIENTO GRANDPRIX</span>
        <h2>Financiamiento simple y transparente</h2>
        <p>Trabajamos sobre un plan base de 50 cuotas. El monto final depende del modelo seleccionado y de la evaluación de cada solicitud.</p>
      </div>
    </div>
    <div class="finance-layout finance-v13">
      <article class="finance-main card-premium">
        <div class="finance-title-row"><div><span class="eyebrow">PLAN BASE</span><h3>50 cuotas con seguimiento claro</h3></div><span class="finance-badge"><i class="fa-solid fa-shield-halved"></i> Evaluación previa</span></div>
        <p>GRANDPRIX confirma disponibilidad, condiciones y cuota del modelo aprobado después de revisar el expediente. No publicamos montos inventados.</p>
        <div class="plan-track" id="planTrack" aria-label="Plan base de 50 cuotas"></div>
        <div class="finance-feature-row">
          <article><i class="fa-solid fa-file-signature"></i><span><b>Solicitud digital</b><small>Completa tus datos en línea</small></span></article>
          <article><i class="fa-solid fa-calculator"></i><span><b>Evaluación clara</b><small>Condiciones según tu expediente</small></span></article>
          <article><i class="fa-solid fa-shield"></i><span><b>Sin sorpresas</b><small>Proceso validado por etapas</small></span></article>
        </div>
      </article>
      <aside class="finance-side card-premium">
        <div class="plan-number"><small>PLAN BASE</small><strong>50</strong><span>cuotas</span></div>
        <div class="choose-model">
          <label>Primera opción de moto</label>
          <select id="modelQuickSelect"><option value="">Cargando catálogo…</option></select>
          <label>Segunda opción</label>
          <select id="modelQuickSelect2"><option value="">Opcional</option></select>
          <div class="quick-model-preview" id="quickModelPreview"><i class="fa-solid fa-motorcycle"></i><span>Selecciona un modelo para ver su ficha.</span></div>
          <button class="primary small" id="quickApplyButton"><i class="fa-solid fa-paper-plane"></i> Solicitar con estas opciones</button>
        </div>
      </aside>
    </div>
  </section>

  <section class="section shell" id="proceso">
    <div class="section-head compact proposal-head">
      <div><span class="eyebrow">CÓMO FUNCIONA</span><h2>Así funciona tu solicitud</h2><p>Un proceso guiado para que sepas siempre qué etapa sigue.</p></div>
    </div>
    <div class="process-line">
      <article><em>01</em><div><b>Perfil personal</b><p>Datos, contacto, ingresos, referencia y modelos preferidos.</p></div></article>
      <article><em>02</em><div><b>Documentos</b><p>Foto de cédula de identidad y recaudos adicionales cuando correspondan.</p></div></article>
      <article><em>03</em><div><b>Visita</b><p>Ubicación GPS y foto de fachada tras validar documentos.</p></div></article>
      <article><em>04</em><div><b>Cita en oficina</b><p>Programación presencial y decisión final de GRANDPRIX.</p></div></article>
    </div>
  </section>

  <section class="section shell institutional-section" id="nosotros">
    <div class="institutional-grid">
      <article class="institution-card">
        <span class="institution-icon"><i class="fa-solid fa-people-group"></i></span>
        <div><span class="eyebrow">QUIÉNES SOMOS</span><h2>Una empresa enfocada en acompañar tu camino</h2><p>GRANDPRIX es una empresa enfocada en facilitar el acceso al financiamiento de motos mediante procesos claros, atención cercana y acompañamiento en cada etapa del cliente.</p></div>
      </article>
      <article class="institution-card">
        <span class="institution-icon"><i class="fa-solid fa-bullseye"></i></span>
        <div><span class="eyebrow">NUESTRA VISIÓN</span><h2>Financiamiento confiable, moderno y transparente</h2><p>Ser una empresa referente en financiamiento de motos en la región, reconocida por su confianza, innovación, servicio al cliente y procesos transparentes.</p></div>
      </article>
      <article class="location-card">
        <span class="institution-icon"><i class="fa-solid fa-location-dot"></i></span>
        <div><span class="eyebrow">NUESTRA UBICACIÓN</span><h2>Visítanos en Maracaibo</h2><p>Centro Comercial Viento Norte, piso 2, oficina 13, Maracaibo.</p><a href="https://www.google.com/maps/search/?api=1&query=Centro+Comercial+Viento+Norte+Maracaibo" target="_blank" rel="noopener">Ver ubicación <i class="fa-solid fa-arrow-up-right-from-square"></i></a></div>
      </article>
    </div>
  </section>

  <section class="section shell faq-section" id="preguntas">
    <div class="section-head compact proposal-head"><div><span class="eyebrow">PREGUNTAS FRECUENTES</span><h2>Lo que debes saber antes de solicitar</h2><p>Información clara para evitar dudas durante el proceso.</p></div></div>
    <div class="faq-list">
      <article class="faq-item"><button><span>¿El financiamiento es de 50 cuotas?</span><i class="fa-solid fa-plus"></i></button><div><p>El sistema trabaja con un plan base de 50 cuotas. El valor exacto depende del modelo y de la evaluación de la solicitud.</p></div></article>
      <article class="faq-item"><button><span>¿Puedo elegir más de una moto?</span><i class="fa-solid fa-plus"></i></button><div><p>Sí. Puedes registrar una primera opción y una segunda alternativa. GRANDPRIX confirma disponibilidad y condiciones durante la evaluación.</p></div></article>
      <article class="faq-item"><button><span>¿Por qué debo registrar dos teléfonos?</span><i class="fa-solid fa-plus"></i></button><div><p>Dos números diferentes son obligatorios para facilitar el contacto y la validación del expediente.</p></div></article>
      <article class="faq-item"><button><span>¿Cuándo se solicita la ubicación de mi vivienda?</span><i class="fa-solid fa-plus"></i></button><div><p>La ubicación GPS y la foto de la fachada se habilitan después de que GRANDPRIX aprueba la documentación inicial.</p></div></article>
      <article class="faq-item"><button><span>¿Cómo sé en qué etapa va mi solicitud?</span><i class="fa-solid fa-plus"></i></button><div><p>Al enviar la solicitud recibirás un código único. Con ese código podrás volver cuando quieras, consultar el estado y adjuntar documentos pendientes.</p></div></article>
    </div>
  </section>

  <section class="section shell" id="seguimiento">
    <div class="tracking-wrap card-premium">
      <div class="tracking-copy"><span class="eyebrow">SEGUIMIENTO DEL EXPEDIENTE</span><h2>Consulta el estado de tu solicitud</h2><p>Usa el código único que recibiste al completar tu solicitud. Con él podrás consultar el avance y adjuntar recaudos pendientes.</p></div>
      <form id="trackingForm" class="tracking-form single-code"><label>Código de seguimiento<input name="accessCode" placeholder="GP-XXXX-XXXX" autocomplete="off" required></label><button><i class="fa-solid fa-arrow-right-to-bracket"></i> Continuar expediente</button></form>
    </div>
    <div id="trackingResult"></div>
  </section>
</main>

<footer class="site-foot">
  <div class="foot-main shell">
    <div class="foot-brand"><img src="assets/grandprix-logo-light.svg" alt="GRANDPRIX Financiamiento de motos"><div class="social-row"><a href="https://www.instagram.com/" target="_blank" rel="noopener" aria-label="Instagram"><i class="fa-brands fa-instagram"></i></a><a href="https://www.facebook.com/" target="_blank" rel="noopener" aria-label="Facebook"><i class="fa-brands fa-facebook-f"></i></a></div></div>
    <div class="foot-column"><h3>GRANDPRIX</h3><a href="#nosotros">Quiénes somos</a><a href="#nosotros">Nuestra visión</a><a href="#modelos">Modelos</a><a href="#financiamiento">Financiamiento</a></div>
    <div class="foot-column"><h3>Atención al cliente</h3><a href="#preguntas">Preguntas frecuentes</a><a href="#proceso">Cómo funciona</a><a href="#seguimiento">Seguimiento</a><a href="https://wa.me/584168675230" target="_blank" rel="noopener">WhatsApp +58 416-8675230</a></div>
    <div class="foot-column location"><h3>Ubicación</h3><p><i class="fa-solid fa-location-dot"></i> Centro Comercial Viento Norte, piso 2, oficina 13, Maracaibo.</p><a href="https://www.google.com/maps/search/?api=1&query=Centro+Comercial+Viento+Norte+Maracaibo" target="_blank" rel="noopener">Ver ubicación</a></div>
    <div class="foot-column whatsapp"><h3>WhatsApp</h3><p>Escríbenos y un asesor te acompañará durante tu proceso.</p><a class="foot-wa" href="https://wa.me/584168675230" target="_blank" rel="noopener"><i class="fa-brands fa-whatsapp"></i> Abrir WhatsApp</a></div>
  </div>
  <div class="foot-bottom"><div class="shell"><span>© <?=date('Y')?> GRANDPRIX Financiamiento de Motos. Todos los derechos reservados.</span><span>Seguro. Claro. Hecho para avanzar.</span></div></div>
</footer>

<div class="mobile-cta">
  <button data-open-apply><i class="fa-solid fa-file-signature"></i> Solicitar financiamiento</button>
</div>

<div class="compare-dock" id="compareDock" aria-hidden="true">
  <div><span class="eyebrow">TUS OPCIONES</span><strong id="compareSummary">Selecciona hasta 2 modelos</strong></div>
  <div class="compare-slots"><button id="compareSlot1"><i class="fa-solid fa-plus"></i><span>Primera opción</span></button><button id="compareSlot2"><i class="fa-solid fa-plus"></i><span>Segunda opción</span></button></div>
  <button class="compare-apply" id="compareApply"><i class="fa-solid fa-paper-plane"></i> Solicitar</button>
</div>

<div class="model-detail-overlay" id="modelDetailOverlay" aria-hidden="true">
  <section class="model-detail-modal">
    <button class="detail-close" id="modelDetailClose"><i class="fa-solid fa-xmark"></i></button>
    <div class="detail-media"><img id="detailImage" src="" alt=""></div>
    <div class="detail-copy"><span class="eyebrow">MODELO GRANDPRIX</span><h2 id="detailName"></h2><p id="detailDescription"></p><div class="detail-meta" id="detailMeta"></div><div class="detail-actions"><button class="secondary" id="detailChoose2"><i class="fa-solid fa-plus"></i> Segunda opción</button><button class="primary" id="detailChoose1"><i class="fa-solid fa-check"></i> Primera opción</button></div></div>
  </section>
</div>

<div class="apply-overlay" id="applyOverlay" aria-hidden="true">
  <section class="apply-modal">
    <button class="apply-close" id="applyClose" aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button>
    <div class="apply-side">
      <div class="side-brand"><img src="assets/grandprix-logo-light.svg" alt="GRANDPRIX"></div>
      <span class="eyebrow light">PROCESO DIGITAL</span>
      <h2>Tu expediente comienza aquí</h2>
      <p>Completa la solicitud en pocos pasos. Recibirás un código único para volver, consultar tu expediente y adjuntar documentos cuando GRANDPRIX los solicite.</p>
      <div class="steps">
        <button class="active" data-step-dot="1"><i>1</i><span><b>Perfil</b><small>Datos del solicitante</small></span></button>
        <button data-step-dot="2"><i>2</i><span><b>Documentos</b><small>Cédula y soportes</small></span></button>
        <button data-step-dot="3"><i>3</i><span><b>Confirmar</b><small>Enviar a revisión</small></span></button>
      </div>
    </div>
    <div class="apply-body">
      <form id="applicationForm" enctype="multipart/form-data">
        <div class="form-step active" data-step="1">
          <span class="eyebrow">PASO 1 DE 3</span>
          <h2>Perfil del solicitante</h2>
          <p>Completa los datos tal como aparecen en tus documentos.</p>
          <div class="form-grid">
            <label>Nombres<input name="firstNames" required maxlength="100"></label>
            <label>Apellidos<input name="lastNames" required maxlength="100"></label>
            <label>Cédula<input name="identityDocument" required maxlength="40" placeholder="V-12345678"></label>
            <label>Edad<input name="age" type="number" min="1" max="120" step="1" inputmode="numeric" autocomplete="off" required placeholder="Ej. 32"></label>
            <label>Teléfono principal<input name="phone" required maxlength="40"></label>
            <label>Segundo teléfono<input name="phone2" required maxlength="40"></label>
            <label>Correo electrónico<input name="email" type="email" required maxlength="190"></label>
            <label class="full">Dirección<input name="address" required maxlength="300"></label>
            <label>Ocupación<input name="occupation" required maxlength="160"></label>
            <label>Carga familiar<input name="familyLoad" type="number" min="0" max="30" required></label>
            <label>Ingreso mensual USD<input name="monthlyIncome" type="number" min="0" step="0.01" required></label>
            <label>¿Cómo conociste GRANDPRIX?
              <select name="referralType" id="referralType" required>
                <option value="">Seleccionar</option>
                <option value="redes">Redes sociales</option>
                <option value="persona">Referido por una persona</option>
              </select>
            </label>
            <label id="referralDetailLabel" class="conditional-field hidden">Detalle<input name="referralDetail" maxlength="160"></label>
            <label>Modelo preferido<select name="modelRequested" id="modelRequested" required><option value="">Cargando catálogo…</option></select></label>
            <label>Segunda opción de modelo<select name="modelRequested2" id="modelRequested2"><option value="">Opcional</option></select></label>
            <label class="full">Comentario adicional<textarea name="notes" maxlength="1000" placeholder="Información que quieras agregar a tu solicitud"></textarea></label>
          </div>
          <div class="form-actions"><span></span><button type="button" class="next" data-next="2">Continuar a documentos <i class="fa-solid fa-arrow-right"></i></button></div>
        </div>
        <div class="form-step" data-step="2">
          <span class="eyebrow">PASO 2 DE 3</span>
          <h2>Documentación</h2>
          <p>En esta etapa solo es obligatoria una foto clara de tu cédula de identidad. Los demás recaudos son opcionales y también podrás cargarlos después usando tu código de seguimiento.</p>
          <div class="upload-grid initial-docs">
            <label class="upload featured"><i class="fa-solid fa-id-card"></i><b>Foto de cédula de identidad</b><small>Obligatoria · JPG, PNG o WEBP · máx. 8 MB</small><input type="file" name="identityCard" accept="image/jpeg,image/png,image/webp" required></label>
            <label class="upload"><i class="fa-solid fa-file-invoice-dollar"></i><b>Soporte de ingresos</b><small>Opcional · puedes cargarlo ahora o después</small><input type="file" name="incomeProof" accept="image/jpeg,image/png,image/webp,application/pdf"></label>
            <label class="upload"><i class="fa-solid fa-paperclip"></i><b>Otro documento</b><small>Opcional · si aplica a tu caso</small><input type="file" name="otherDocument" accept="image/jpeg,image/png,image/webp,application/pdf"></label>
          </div>
          <div class="form-actions"><button type="button" class="back" data-back="1"><i class="fa-solid fa-arrow-left"></i> Volver</button><button type="button" class="next" data-next="3">Revisar solicitud <i class="fa-solid fa-arrow-right"></i></button></div>
        </div>
        <div class="form-step" data-step="3">
          <span class="eyebrow">PASO 3 DE 3</span>
          <h2>Confirmación</h2>
          <p>Revisa que la información esté correcta. Al enviar, tu solicitud pasará a validación documental.</p>
          <div class="confirmation-box">
            <div><i class="fa-solid fa-circle-check"></i><span>Perfil y contacto del solicitante</span></div>
            <div><i class="fa-solid fa-circle-check"></i><span>Documentación cargada</span></div>
            <div><i class="fa-solid fa-circle-check"></i><span>Código único para continuar el expediente</span></div>
          </div>
          <div class="form-actions"><button type="button" class="back" data-back="2"><i class="fa-solid fa-arrow-left"></i> Volver</button><button id="submitApplication" type="submit" class="submit"><i class="fa-solid fa-paper-plane"></i> Enviar solicitud</button></div>
        </div>
      </form>
      <section id="applicationSuccess" class="application-success">
        <div class="success-icon"><i class="fa-solid fa-circle-check"></i></div>
        <h2>Solicitud enviada</h2>
        <p>Guarda especialmente tu código de seguimiento. Es la llave para volver al website, consultar el proceso y cargar documentos pendientes.</p>
        <div class="success-grid">
          <article><small>N.º de solicitud</small><b id="successCode"></b></article>
          <article class="continuation-code"><small>Código para continuar</small><b id="successToken"></b><em>No compartas este código con terceros.</em></article>
        </div>
        <button id="successClose" class="primary">Cerrar</button>
      </section>
    </div>
  </section>
</div>

<script>window.GP_PUBLIC={api:'api.php',csrf:<?=json_encode($csrf,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>};</script>
<script src="assets/site.js?v=20.4.0"></script>
</body>
</html>
