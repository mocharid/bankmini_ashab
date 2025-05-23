<?php
require_once 'includes/config.php'; // Memuat BASE_URL
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no">
  <title>SCHOBANK - SMK PLUS ASHABULYAMIN Cianjur</title>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css"/>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.11.4/gsap.min.js"></script>
  <style>
    /* === CSS Variables === */
    :root {
      --dark-blue: #0a192f;
      --medium-blue: #172a45;
      --light-blue: #64ffda;
      --yellow: #facc15;
      --white: #e6f1ff;
      --button-blue: #0a6c9c;
      --button-blue-hover: #095b85;
      --glow-blue: rgba(10, 108, 156, 0.4);
      --shadow: 0 8px 30px rgba(0, 0, 0, 0.15);
      --text-shadow: 0 2px 10px rgba(100, 255, 218, 0.4);
    }

    /* === Base Styles === */
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
      font-family: 'Poppins', -apple-system, BlinkMacSystemFont, sans-serif;
    }

    html, body {
      width: 100%;
      height: 100vh;
      overflow: hidden; /* Prevent scrollbars on desktop */
      background: linear-gradient(135deg, var(--dark-blue) 0%, #0f3460 100%);
      color: var(--white);
      touch-action: pan-y;
      -webkit-text-size-adjust: 100%;
      scroll-behavior: smooth;
      overscroll-behavior-y: contain;
    }

    body {
      display: flex;
      justify-content: center;
      align-items: center;
      opacity: 0;
      animation: fadeIn 1s ease-in forwards;
      position: relative;
      min-height: 100vh; /* Ensure content fits viewport */
    }

    /* === Container === */
    .container {
      display: flex;
      align-items: center;
      justify-content: space-between;
      width: 100%;
      max-width: 1400px;
      height: 100vh; /* Full viewport height */
      padding: 3rem 2rem;
      position: relative;
      z-index: 10;
    }

    /* === Text Section === */
    .text-section {
      max-width: 50%;
      opacity: 0;
      transform: translateY(20px);
      animation: fadeInUp 1s ease-out forwards;
      animation-delay: 0.3s;
    }

    .text-section h1 {
      font-size: clamp(2.5rem, 5vw, 4rem);
      margin-bottom: 0.8rem;
      background: linear-gradient(90deg, var(--white) 0%, var(--light-blue) 100%);
      -webkit-background-clip: text;
      different-clip: text;
      color: transparent;
      line-height: 1.2;
      font-weight: 800;
      text-shadow: var(--text-shadow);
      letter-spacing: -0.5px;
    }

    .text-section h2 {
      font-size: clamp(1.5rem, 3vw, 2rem);
      margin-bottom: 1.5rem;
      color: #ccd6f6;
      font-weight: 500;
      text-shadow: 0 0 8px rgba(100, 255, 218, 0.3);
    }

    .text-section p {
      font-size: clamp(1rem, 1.8vw, 1.3rem);
      margin-bottom: 2rem;
      color: #a8b2d1;
      line-height: 1.7;
      font-weight: 300;
      max-width: 90%;
    }

    .tagline {
      font-size: clamp(0.9rem, 1.5vw, 1rem);
      color: var(--light-blue);
      margin-top: 1.5rem;
      font-style: italic;
      position: relative;
      display: inline-block;
      font-weight: 400;
    }

    .tagline::after {
      content: '|';
      position: absolute;
      right: -10px;
      animation: blink 0.7s infinite;
    }

    /* === Button === */
    .btn {
      padding: 1rem 2.5rem;
      background: linear-gradient(45deg, var(--button-blue), #00b7eb);
      color: var(--white);
      font-weight: 600;
      border: none;
      border-radius: 50px;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      opacity: 0;
      transform: translateY(20px);
      animation: fadeInUp 1s ease-out forwards;
      animation-delay: 0.5s;
      transition: all 0.4s cubic-bezier(0.25, 0.8, 0.25, 1);
      font-size: 1.1rem;
      box-shadow: var(--shadow);
      position: relative;
      overflow: hidden;
      text-decoration: none;
      min-width: 200px;
      text-align: center;
      z-index: 1;
    }

    .btn:hover {
      transform: translateY(-4px) scale(1.05);
      box-shadow: 0 12px 30px var(--glow-blue);
      background: linear-gradient(45deg, var(--button-blue-hover), #00acee);
    }

    .btn:active {
      transform: translateY(1px) scale(0.98);
    }

    .btn::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: linear-gradient(45deg, transparent, rgba(255, 255, 255, 0.1), transparent);
      transform: translateX(-100%);
      transition: transform 0.6s ease;
      z-index: -1;
    }

    .btn:hover::before {
      transform: translateX(100%);
    }

    /* === Image Section === */
    .image-section {
      width: 45%;
      height: 100%;
      display: flex;
      justify-content: center;
      align-items: center;
      position: relative;
      opacity: 0;
      animation: fadeIn 1s ease-in forwards;
      animation-delay: 0.4s;
    }

    .bank-icon {
      width: 400px;
      height: 400px;
      background: radial-gradient(circle, var(--light-blue) 0%, rgba(100, 255, 218, 0.2) 70%);
      border-radius: 30% 70% 70% 30% / 30% 30% 70% 70%;
      display: flex;
      justify-content: center;
      align-items: center;
      box-shadow: 0 0 80px rgba(100, 255, 218, 0.4);
      position: relative;
      animation: float 6s ease-in-out infinite;
      transition: all 0.4s ease;
      will-change: transform;
      backdrop-filter: blur(2px);
    }

    .bank-icon::before {
      content: "💰";
      font-size: 140px;
      animation: pulse 2.5s infinite alternate;
      filter: drop-shadow(0 0 25px var(--light-blue));
      transition: all 0.3s ease;
    }

    .bank-icon:hover {
      transform: scale(1.05) rotate(5deg);
      box-shadow: 0 0 100px rgba(100, 255, 218, 0.6);
    }

    .bank-icon:hover::before {
      transform: scale(1.1);
      filter: drop-shadow(0 0 30px var(--light-blue));
    }

    /* === Background Effects === */
    .circle {
      position: absolute;
      border-radius: 50%;
      pointer-events: none;
      filter: blur(80px);
      opacity: 0.6;
      z-index: -1;
      will-change: transform;
    }

    .circle1 {
      width: 500px;
      height: 500px;
      top: -250px;
      right: -250px;
      background: radial-gradient(circle, var(--light-blue) 0%, transparent 70%);
      animation: pulse 4s infinite alternate;
    }

    .circle2 {
      width: 400px;
      height: 400px;
      bottom: -200px;
      left: -200px;
      background: radial-gradient(circle, var(--yellow) 0%, transparent 70%);
      animation: pulse 5s infinite alternate-reverse;
    }

    .floating-coins {
      position: absolute;
      width: 100%;
      height: 100%;
      pointer-events: none;
      z-index: -1;
    }

    .coin {
      position: absolute;
      font-size: 30px;
      animation: float 7s ease-in-out infinite;
      opacity: 0.7;
      transition: all 0.4s ease;
      will-change: transform;
      filter: drop-shadow(0 2px 5px rgba(0, 0, 0, 0.3));
    }

    .coin:hover {
      transform: scale(1.4) rotate(15deg);
      opacity: 1;
      animation-play-state: paused;
    }

    .particle-bg {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      z-index: 1;
      pointer-events: none;
      overflow: hidden;
    }

    .particle {
      position: absolute;
      width: 8px;
      height: 8px;
      background: var(--light-blue);
      border-radius: 50%;
      opacity: 0.5;
      animation: drift 12s infinite linear;
      will-change: transform;
    }

    .interactive-bg {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      overflow: hidden;
      z-index: 0;
    }

    .bg-element {
      position: absolute;
      border-radius: 50%;
      background: rgba(100, 255, 218, 0.1);
      pointer-events: none;
      transition: all 0.8s cubic-bezier(0.165, 0.84, 0.44, 1);
      will-change: transform;
      backdrop-filter: blur(1px);
    }

    /* === Animations === */
    @keyframes fadeIn {
      from { opacity: 0; }
      to { opacity: 1; }
    }

    @keyframes fadeInUp {
      from { opacity: 0; transform: translateY(30px); }
      to { opacity: 1; transform: translateY(0); }
    }

    @keyframes float {
      0% { transform: translateY(0) rotate(0deg); }
      50% { transform: translateY(-25px) rotate(5deg); }
      100% { transform: translateY(0) rotate(0deg); }
    }

    @keyframes pulse {
      0% { transform: scale(1); opacity: 0.8; }
      100% { transform: scale(1.15); opacity: 0.5; }
    }

    @keyframes ripple {
      to { transform: translate(-50%, -50%) scale(3); opacity: 0; }
    }

    @keyframes drift {
      0% { transform: translate(0, 0); opacity: 0.5; }
      50% { opacity: 0.8; }
      100% { transform: translate(120px, 120px); opacity: 0; }
    }

    @keyframes blink {
      50% { opacity: 0; }
    }

    @keyframes gradientShift {
      0% { background-position: 0% 50%; }
      50% { background-position: 100% 50%; }
      100% { background-position: 0% 50%; }
    }

    /* === Responsive Design === */
    @media (max-width: 1024px) {
      .container {
        padding: 2rem;
      }
      
      .bank-icon {
        width: 350px;
        height: 350px;
      }
      
      .bank-icon::before {
        font-size: 120px;
      }
    }

    @media (max-width: 768px) {
      html, body {
        height: auto;
        min-height: 100vh;
        overflow-y: auto; /* Allow scrolling on mobile */
      }

      .container {
        flex-direction: column-reverse;
        height: auto;
        padding: 2rem 1.5rem;
        text-align: center;
        justify-content: flex-start;
      }

      .text-section {
        max-width: 100%;
        margin-bottom: 2rem;
        padding-top: 2rem;
      }

      .text-section h1 {
        font-size: clamp(2rem, 6vw, 3rem);
        margin-bottom: 0.5rem;
      }

      .text-section h2 {
        font-size: clamp(1.2rem, 4vw, 1.6rem);
        margin-bottom: 1rem;
      }

      .text-section p {
        font-size: clamp(0.9rem, 3vw, 1.1rem);
        margin-bottom: 1.5rem;
        max-width: 100%;
      }

      .tagline {
        font-size: clamp(0.8rem, 3vw, 0.9rem);
        margin-top: 1rem;
      }

      .btn {
        padding: 0.9rem 2rem;
        font-size: 1rem;
        min-width: 180px;
        margin: 0 auto;
      }

      .image-section {
        width: 100%;
        height: 250px;
        margin: 1rem 0;
      }

      .bank-icon {
        width: 220px;
        height: 220px;
      }

      .bank-icon::before {
        font-size: 80px;
      }

      .circle1, .circle2 {
        display: none;
      }

      .coin {
        font-size: 24px;
      }
    }

    @media (max-width: 480px) {
      .container {
        padding: 1.5rem 1rem;
      }
      
      .bank-icon {
        width: 200px;
        height: 200px;
      }
      
      .bank-icon::before {
        font-size: 70px;
      }
      
      .btn {
        width: 100%;
        max-width: 220px;
      }
    }

    /* === Special Effects === */
    .sparkle {
      position: absolute;
      width: 6px;
      height: 6px;
      background: white;
      border-radius: 50%;
      pointer-events: none;
      opacity: 0;
      animation: sparkle 1s ease-out;
      filter: drop-shadow(0 0 2px var(--light-blue));
    }

    @keyframes sparkle {
      0% { transform: scale(0); opacity: 0; }
      50% { transform: scale(1.2); opacity: 0.8; }
      100% { transform: scale(0); opacity: 0; }
    }
  </style>
</head>
<body>
  <!-- Background Effects -->
  <div class="particle-bg" id="particleBg"></div>
  <div class="interactive-bg" id="interactiveBg"></div>
  
  <!-- Main Content -->
  <div class="container">
    <div class="text-section">
      <h1>SCHOBANK DIGITAL SYSTEM</h1>
      <h2>Aplikasi Bank Mini Online Sekolah</h2>
      <p>Program inovatif untuk melatih siswa mengelola keuangan sejak dini. Nikmati fitur tabungan dan edukasi finansial dalam platform yang aman dan menyenangkan.</p>
      <div>
        <a href="<?php echo BASE_URL; ?>pages/login.php" class="btn">Mulai Sekarang</a>
      </div>
      <div class="tagline" id="tagline"></div>
    </div>
    <div class="image-section">
      <div class="bank-icon" id="bankIcon">
        <div class="coin-animation"></div>
      </div>
      <div class="circle circle1"></div>
      <div class="circle circle2"></div>
      <div class="floating-coins" id="floatingCoins"></div>
    </div>
  </div>

  <script>
    // Prevent zooming and unwanted scrolling on mobile
    document.addEventListener('touchmove', function(e) {
      if (e.scale !== 1) e.preventDefault();
    }, { passive: false });

    let lastTouchEnd = 0;
    document.addEventListener('touchend', function(e) {
      const now = Date.now();
      if (now - lastTouchEnd <= 300) e.preventDefault();
      lastTouchEnd = now;
    }, { passive: false });

    // Typewriter effect for tagline
    const taglineTexts = [
      '"Belajar Keuangan dengan Cara yang Menyenangkan!"',
      '"Tabungan Digital untuk Generasi Cerdas"',
      '"Mengelola Keuangan Sejak Dini"'
    ];
    const taglineElement = document.getElementById('tagline');
    let currentTagline = 0;
    let charIndex = 0;
    let isDeleting = false;
    let typingSpeed = 100;

    function typeTagline() {
      const currentText = taglineTexts[currentTagline];
      
      if (isDeleting) {
        taglineElement.textContent = currentText.substring(0, charIndex - 1);
        charIndex--;
        typingSpeed = 50;
      } else {
        taglineElement.textContent = currentText.substring(0, charIndex + 1);
        charIndex++;
        typingSpeed = 100;
      }

      if (!isDeleting && charIndex === currentText.length) {
        isDeleting = true;
        typingSpeed = 1500; // Pause at end
      } else if (isDeleting && charIndex === 0) {
        isDeleting = false;
        currentTagline = (currentTagline + 1) % taglineTexts.length;
        typingSpeed = 500; // Pause before typing next
      }

      setTimeout(typeTagline, typingSpeed);
    }

    // Start typing after page loads
    setTimeout(typeTagline, 1500);

    // Initialize interactive background
    const initInteractiveBg = () => {
      const interactiveBg = document.getElementById('interactiveBg');
      const colors = [
        'rgba(100, 255, 218, 0.15)',
        'rgba(250, 204, 21, 0.15)',
        'rgba(23, 42, 69, 0.25)',
        'rgba(10, 108, 156, 0.2)'
      ];
      
      for (let i = 0; i < 30; i++) {
        const element = document.createElement('div');
        element.classList.add('bg-element');
        const size = Math.random() * 200 + 50;
        const posX = Math.random() * 100;
        const posY = Math.random() * 100;
        const color = colors[Math.floor(Math.random() * colors.length)];
        const opacity = Math.random() * 0.3 + 0.1;
        
        element.style.width = `${size}px`;
        element.style.height = `${size}px`;
        element.style.left = `${posX}%`;
        element.style.top = `${posY}%`;
        element.style.background = color;
        element.style.opacity = opacity;
        element.style.filter = `blur(${Math.random() * 10 + 5}px)`;
        
        interactiveBg.appendChild(element);
      }
    };

    // Initialize particle background
    const initParticleBg = () => {
      const particleBg = document.getElementById('particleBg');
      const particleCount = window.innerWidth < 768 ? 40 : 80;
      
      for (let i = 0; i < particleCount; i++) {
        const particle = document.createElement('div');
        particle.classList.add('particle');
        
        const size = Math.random() * 6 + 3;
        const posX = Math.random() * 100;
        const posY = Math.random() * 100;
        const delay = Math.random() * 10;
        const duration = 8 + Math.random() * 15;
        const opacity = Math.random() * 0.5 + 0.2;
        
        particle.style.width = `${size}px`;
        particle.style.height = `${size}px`;
        particle.style.left = `${posX}%`;
        particle.style.top = `${posY}%`;
        particle.style.animationDelay = `${delay}s`;
        particle.style.animationDuration = `${duration}s`;
        particle.style.opacity = opacity;
        
        // Random color between light blue and yellow
        if (Math.random() > 0.7) {
          particle.style.background = 'var(--yellow)';
        }
        
        particleBg.appendChild(particle);
      }
    };

    // Initialize floating coins
    const initFloatingCoins = () => {
      const floatingCoins = document.getElementById('floatingCoins');
      const coinSymbols = ['💰', '💵', '💴', '💶', '💷', '💳', '🧾', '🏦'];
      const coinCount = window.innerWidth < 768 ? 8 : 15;
      
      for (let i = 0; i < coinCount; i++) {
        const coin = document.createElement('div');
        coin.classList.add('coin');
        coin.textContent = coinSymbols[Math.floor(Math.random() * coinSymbols.length)];
        
        const posX = Math.random() * 80 + 10;
        const posY = Math.random() * 80 + 10;
        const delay = Math.random() * 5;
        const duration = 5 + Math.random() * 7;
        const size = Math.random() * 0.8 + 0.7;
        
        coin.style.left = `${posX}%`;
        coin.style.top = `${posY}%`;
        coin.style.animationDelay = `${delay}s`;
        coin.style.animationDuration = `${duration}s`;
        coin.style.transform = `scale(${size})`;
        
        // Add click/tap effect
        coin.addEventListener('click', createSparkle);
        coin.addEventListener('touchstart', createSparkle, { passive: true });
        
        floatingCoins.appendChild(coin);
      }
    };

    // Create sparkle effect
    function createSparkle(e) {
      const sparkle = document.createElement('div');
      sparkle.classList.add('sparkle');
      
      const x = e.clientX || e.touches[0].clientX;
      const y = e.clientY || e.touches[0].clientY;
      
      sparkle.style.left = `${x}px`;
      sparkle.style.top = `${y}px`;
      
      document.body.appendChild(sparkle);
      
      setTimeout(() => {
        sparkle.remove();
      }, 1000);
    }

    // Handle mouse/touch movement for parallax
    const handleMove = (e) => {
      const isTouch = e.type.includes('touch');
      const clientX = isTouch ? e.touches[0].clientX : e.clientX;
      const clientY = isTouch ? e.touches[0].clientY : e.clientY;
      
      const mouseX = (clientX / window.innerWidth) * 2 - 1;
      const mouseY = (clientY / window.innerHeight) * 2 - 1;
      
      // Move background elements
      const bgElements = document.querySelectorAll('.bg-element');
      bgElements.forEach((el, i) => {
        const speed = 0.05 + (i * 0.01);
        const x = mouseX * 40 * speed;
        const y = mouseY * 40 * speed;
        el.style.transform = `translate(${x}px, ${y}px)`;
      });
      
      // Move coins slightly
      const coins = document.querySelectorAll('.coin');
      coins.forEach((coin, i) => {
        const speed = 0.03 + (i * 0.005);
        const x = mouseX * 30 * speed;
        const y = mouseY * 30 * speed;
        const currentTransform = coin.style.transform.match(/scale\(([^)]+)\)/) || ['', '1'];
        coin.style.transform = `translate(${x}px, ${y}px) scale(${currentTransform[1]})`;
      });
      
      // Tilt bank icon
      const bankIcon = document.getElementById('bankIcon');
      const tiltX = mouseY * 10;
      const tiltY = mouseX * -10;
      bankIcon.style.transform = `rotateX(${tiltX}deg) rotateY(${tiltY}deg)`;
    };

    // Add ripple effect to buttons
    const initButtonRipple = () => {
      const buttons = document.querySelectorAll('.btn');
      
      buttons.forEach(btn => {
        btn.addEventListener('click', function(e) {
          const rect = btn.getBoundingClientRect();
          const x = e.clientX - rect.left;
          const y = e.clientY - rect.top;
          
          const ripple = document.createElement('span');
          ripple.style.position = 'absolute';
          ripple.style.borderRadius = '50%';
          ripple.style.background = 'rgba(255, 255, 255, 0.4)';
          ripple.style.transform = 'translate(-50%, -50%) scale(0)';
          ripple.style.animation = 'ripple 0.8s linear';
          ripple.style.pointerEvents = 'none';
          
          const size = Math.max(rect.width, rect.height) * 1.5;
          ripple.style.width = ripple.style.height = `${size}px`;
          ripple.style.left = `${x}px`;
          ripple.style.top = `${y}px`;
          
          btn.appendChild(ripple);
          
          setTimeout(() => {
            ripple.remove();
          }, 800);
        });
      });
    };

    // Initialize bank icon animation
    const initBankIconAnimation = () => {
      const bankIcon = document.getElementById('bankIcon');
      const emojis = ['💰', '💳', '🏦', '📊', '💵', '💲'];
      
      let currentEmoji = 0;
      setInterval(() => {
        bankIcon.innerHTML = `<div style="font-size:140px;filter:drop-shadow(0 0 25px var(--light-blue));">${emojis[currentEmoji]}</div>`;
        currentEmoji = (currentEmoji + 1) % emojis.length;
      }, 3000);
    };

    // Initialize all components
    const init = () => {
      initInteractiveBg();
      initParticleBg();
      initFloatingCoins();
      initButtonRipple();
      initBankIconAnimation();
      
      // Add event listeners for movement
      document.addEventListener('mousemove', handleMove);
      document.addEventListener('touchmove', handleMove, { passive: true });
      
      // Add sparkle effect to bank icon on hover
      const bankIcon = document.getElementById('bankIcon');
      bankIcon.addEventListener('mouseenter', () => {
        for (let i = 0; i < 10; i++) {
          setTimeout(() => {
            createSparkle({
              clientX: bankIcon.getBoundingClientRect().left + Math.random() * bankIcon.offsetWidth,
              clientY: bankIcon.getBoundingClientRect().top + Math.random() * bankIcon.offsetHeight
            });
          }, i * 100);
        }
      });
    };

    // Start initialization after page loads
    window.addEventListener('load', init);
  </script>
</body>
</html>