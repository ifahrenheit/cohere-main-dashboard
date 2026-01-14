<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NorWest Home Planning Hub</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #000000;
            color: #333;
            margin: 0;
            padding: 0;
            position: relative;
        }

        /* Scrolling background container */
        .background-fixed {
            position: absolute;
            top: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 80%;
            height: 100%;
            background: url('https://assets.cdn.filesafe.space/iLx1UVnYQl6Rm9D3eeVy/media/694a07329cbb5074c3b91bcd.jpg');
            background-size: auto 100%;
            background-position: top center;
            background-repeat: no-repeat;
            z-index: 0;
        }

        /* Main content wrapper */
        .content-wrapper {
            position: relative;
            width: 80%;
            margin: 0 auto;
            background: transparent;
            z-index: 1;
        }

        /* Header Section */
        .header {
            background: linear-gradient(rgba(26, 35, 50, 0.6), rgba(45, 62, 80, 0.7)),
                        url('https://assets.cdn.filesafe.space/iLx1UVnYQl6Rm9D3eeVy/media/694896e3106fdcc1f26d2691.jpg');
            background-size: cover;
            background-position: center;
            color: white;
            padding: 60px 20px 80px;
            text-align: center;
            position: relative;
            width: 100%;
        }

        .nav {
            display: flex;
            justify-content: center;
            gap: 30px;
            margin-bottom: 40px;
            flex-wrap: wrap;
        }

        .nav a {
            color: white;
            text-decoration: none;
            font-size: 16px;
            transition: opacity 0.3s;
        }

        .nav a:hover {
            opacity: 0.7;
        }

        .hero-title {
            font-size: clamp(2rem, 5vw, 3.5rem);
            margin-bottom: 15px;
            font-weight: 700;
            color: #f5d78e;
        }

        .hero-subtitle {
            font-size: clamp(1rem, 2.5vw, 1.3rem);
            margin-bottom: 30px;
            opacity: 0.9;
        }

        .start-section {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 30px;
            margin-top: 40px;
            flex-wrap: wrap;
        }

        .start-icon {
            font-size: 60px;
        }

        .start-text h3 {
            font-size: 1.8rem;
            margin-bottom: 10px;
        }

        .start-text p {
            font-size: 1rem;
            line-height: 1.6;
            max-width: 400px;
        }

        .cta-button {
            background: #5a7c5f;
            color: white;
            padding: 18px 40px;
            border: none;
            border-radius: 30px;
            font-size: 1.2rem;
            font-weight: 600;
            cursor: pointer;
            margin-top: 40px;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }

        .cta-button:hover {
            background: #4a6c4f;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.3);
        }

        /* Journey Section */
        .journey-container {
            max-width: 100%;
            margin: -40px auto 60px;
            padding: 60px 20px;
            position: relative;
            min-height: 2000px;
            background: transparent;
        }

        .explore-text {
            text-align: center;
            font-size: 1rem;
            margin-bottom: 60px;
            color: #2d3e50;
            background: rgba(255, 255, 255, 0.9);
            padding: 12px 25px;
            border-radius: 30px;
            display: inline-block;
            width: auto;
            margin-left: 50%;
            transform: translateX(-50%);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        /* Cards Layout - Positioned along winding path */
        .journey-steps {
            position: relative;
            height: 100%;
        }

        .step-row {
            position: absolute;
            display: flex;
            align-items: center;
            gap: 15px;
            animation: fadeInUp 0.6s ease-out forwards;
            opacity: 0;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Position each step along the winding path */
        .step-row:nth-child(1) {
            left: 5%;
            top: 0;
            animation-delay: 0.1s;
        }

        .step-row:nth-child(2) {
            right: 10%;
            top: 150px;
            animation-delay: 0.2s;
        }

        .step-row:nth-child(3) {
            left: 8%;
            top: 300px;
            animation-delay: 0.3s;
        }

        .step-row:nth-child(4) {
            right: 5%;
            top: 450px;
            animation-delay: 0.4s;
        }

        .step-row:nth-child(5) {
            left: 10%;
            top: 600px;
            animation-delay: 0.5s;
        }

        .step-row:nth-child(6) {
            right: 8%;
            top: 750px;
            animation-delay: 0.6s;
        }

        .step-row:nth-child(7) {
            left: 12%;
            top: 900px;
            animation-delay: 0.7s;
        }

        .step-row:nth-child(8) {
            right: 12%;
            top: 1050px;
            animation-delay: 0.8s;
        }

        .step-row:nth-child(9) {
            left: 15%;
            top: 1200px;
            animation-delay: 0.9s;
        }

        .step-row:nth-child(10) {
            left: 50%;
            transform: translateX(-50%);
            top: 1350px;
            animation-delay: 1s;
        }

        .step-row.left {
            flex-direction: row;
        }

        .step-row.right {
            flex-direction: row-reverse;
        }

        .step-number {
            flex-shrink: 0;
            width: 55px;
            height: 55px;
            background: linear-gradient(135deg, #5a7c5f, #4a6c4f);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.6rem;
            font-weight: bold;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.3);
            z-index: 2;
            border: 3px solid white;
            position: relative;
        }

        .step-number::before {
            content: '';
            position: absolute;
            width: 100%;
            height: 100%;
            border-radius: 50%;
            border: 2px dashed #5a7c5f;
            top: -6px;
            left: -6px;
            right: -6px;
            bottom: -6px;
            opacity: 0.5;
        }

        .step-card {
            flex: 1;
            max-width: 380px;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.2);
            cursor: pointer;
            transition: all 0.3s;
            border: 2px solid transparent;
        }

        .step-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.25);
            border-color: #5a7c5f;
            background: rgba(255, 255, 255, 1);
        }

        .step-card.active {
            border-color: #5a7c5f;
            background: rgba(249, 253, 249, 1);
            box-shadow: 0 12px 40px rgba(90, 124, 95, 0.3);
        }

        .card-content {
            display: flex;
            gap: 15px;
            align-items: flex-start;
        }

        .card-icon {
            font-size: 2.5rem;
            flex-shrink: 0;
        }

        .card-text h3 {
            font-size: 1.2rem;
            margin-bottom: 8px;
            color: #2d3e50;
        }

        .card-text p {
            font-size: 0.9rem;
            line-height: 1.5;
            color: #666;
        }

        .card-button {
            margin-top: 12px;
            padding: 8px 18px;
            background: #5a7c5f;
            color: white;
            border: none;
            border-radius: 20px;
            cursor: pointer;
            font-size: 0.85rem;
            transition: background 0.3s;
        }

        .card-button:hover {
            background: #4a6c4f;
        }

        /* Footer */
        .footer {
            background: rgba(26, 35, 50, 0.95);
            color: white;
            text-align: center;
            padding: 40px 20px;
            margin-top: 60px;
            width: 100%;
        }

        .footer-tagline {
            font-size: 1.3rem;
            margin-bottom: 20px;
            font-style: italic;
        }

        .footer-links {
            display: flex;
            justify-content: center;
            gap: 30px;
            flex-wrap: wrap;
        }

        .footer-links a {
            color: #f5d78e;
            text-decoration: none;
            transition: opacity 0.3s;
        }

        .footer-links a:hover {
            opacity: 0.7;
        }

        /* Mobile Responsive */
        @media (max-width: 1200px) {
            .content-wrapper {
                width: 90%;
            }

            .background-fixed {
                width: 90%;
            }
        }

        @media (max-width: 768px) {
            .content-wrapper {
                width: 95%;
            }

            .background-fixed {
                width: 95%;
            }

            .journey-container {
                min-height: auto;
                padding: 40px 15px;
            }

            .journey-steps {
                position: static;
            }

            .step-row {
                position: relative !important;
                left: auto !important;
                right: auto !important;
                top: auto !important;
                transform: none !important;
                flex-direction: row !important;
                margin-bottom: 30px;
                width: 100%;
            }

            .step-number {
                width: 50px;
                height: 50px;
                font-size: 1.4rem;
            }

            .step-number::before {
                top: -5px;
                left: -5px;
                right: -5px;
                bottom: -5px;
            }

            .step-card {
                max-width: 100%;
            }

            .card-icon {
                font-size: 2rem;
            }

            .card-text h3 {
                font-size: 1.1rem;
            }

            .card-text p {
                font-size: 0.85rem;
            }

            .start-section {
                flex-direction: column;
                text-align: center;
            }

            .start-text {
                text-align: center;
            }

            .explore-text {
                margin-left: 0;
                transform: none;
                width: calc(100% - 40px);
            }
        }

        /* Additional styling for special sections */
        .blueprint-card {
            background: linear-gradient(135deg, #f5f1e8, #e8e4d8);
        }

        .video-badge {
            position: absolute;
            top: 20px;
            right: 20px;
            background: #4fa3a8;
            color: white;
            padding: 10px 20px;
            border-radius: 50%;
            font-size: 0.9rem;
            font-weight: bold;
            text-align: center;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }
    </style>
</head>
<body>
    <!-- Fixed Background -->
    <div class="background-fixed"></div>

    <!-- Content Wrapper (80% width) -->
    <div class="content-wrapper">
    <!-- Header -->
    <div class="header">
        <nav class="nav">
            <a href="#home">Home</a>
            <a href="#blueprint">Blueprint Builder</a>
            <a href="#about">About</a>
            <a href="#contact">Contact</a>
        </nav>

        <h1 class="hero-title">NorWest Home Planning Hub</h1>
        <p class="hero-subtitle">A calm place to understand the selling process</p>

        <div class="start-section">
            <div class="start-icon">🧭</div>
            <div class="start-text">
                <h3>Start Here</h3>
                <p>This is your guided path through the selling process. Navigate your home selling journey with confidence and clarity.</p>
            </div>
        </div>

        <button class="cta-button" onclick="scrollToJourney()">Begin Your Blueprint Builder</button>

        <div class="video-badge">
            Monthly<br>Giveaway<br>⭐⭐⭐
        </div>
    </div>

    <!-- Journey Section -->
    <div class="journey-container" id="journey">
        <p class="explore-text">Or explore the path below.</p>
        
        <div class="journey-steps">
            <!-- Step 1 -->
            <div class="step-row left">
                <div class="step-number">1</div>
                <div class="step-card" onclick="handleCardClick(1)">
                    <div class="card-content">
                        <div class="card-icon">🧭</div>
                        <div class="card-text">
                            <h3>Start Here</h3>
                            <p>Beginning your selling journey. Understand the first steps and set your pace.</p>
                            <button class="card-button">Explore this waypoint →</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Step 2 -->
            <div class="step-row right">
                <div class="step-number">2</div>
                <div class="step-card" onclick="handleCardClick(2)">
                    <div class="card-content">
                        <div class="card-icon">🔄</div>
                        <div class="card-text">
                            <h3>How the Selling Process Works</h3>
                            <p>Get a clear overview of the entire home selling timeline from start to finish.</p>
                            <button class="card-button">Learn More →</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Step 3 -->
            <div class="step-row left">
                <div class="step-number">3</div>
                <div class="step-card blueprint-card" onclick="handleCardClick(3)">
                    <div class="card-content">
                        <div class="card-icon">🗺️</div>
                        <div class="card-text">
                            <h3>The Listing Blueprint Builder</h3>
                            <p>Your journey map. Create a customized selling plan tailored to your specific goals and timeline. Design your strategy.</p>
                            <button class="card-button">Open Blueprint Builder</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Step 4 -->
            <div class="step-row right">
                <div class="step-number">4</div>
                <div class="step-card" onclick="handleCardClick(4)">
                    <div class="card-content">
                        <div class="card-icon">📊</div>
                        <div class="card-text">
                            <h3>Understanding Market Conditions</h3>
                            <p>Analyze current real estate trends and understand how they affect your sale.</p>
                            <button class="card-button">View Market Data →</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Step 5 -->
            <div class="step-row left">
                <div class="step-number">5</div>
                <div class="step-card" onclick="handleCardClick(5)">
                    <div class="card-content">
                        <div class="card-icon">🏠</div>
                        <div class="card-text">
                            <h3>Home Value and Pricing</h3>
                            <p>Discover your home's worth and determine the right pricing strategy.</p>
                            <button class="card-button">Get Home Value →</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Step 6 -->
            <div class="step-row right">
                <div class="step-number">6</div>
                <div class="step-card" onclick="handleCardClick(6)">
                    <div class="card-content">
                        <div class="card-icon">🛠️</div>
                        <div class="card-text">
                            <h3>Preparing the Home</h3>
                            <p>Tips and resources for staging, repairs, and making your home market-ready.</p>
                            <button class="card-button">Preparation Guide →</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Step 7 -->
            <div class="step-row left">
                <div class="step-number">7</div>
                <div class="step-card" onclick="handleCardClick(7)">
                    <div class="card-content">
                        <div class="card-icon">📸</div>
                        <div class="card-text">
                            <h3>Presentation: A Visual Guide</h3>
                            <p>Learn how to showcase your home with high-quality photos, virtual tours, and compelling listings.</p>
                            <button class="card-button">Visual Tips →</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Step 8 -->
            <div class="step-row right">
                <div class="step-number">8</div>
                <div class="step-card" onclick="handleCardClick(8)">
                    <div class="card-content">
                        <div class="card-icon">🏷️</div>
                        <div class="card-text">
                            <h3>Going Live on the Market</h3>
                            <p>Launch your listing and manage showings, offers, and negotiations effectively.</p>
                            <button class="card-button">Launch Strategy →</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Step 9 -->
            <div class="step-row left">
                <div class="step-number">9</div>
                <div class="step-card" onclick="handleCardClick(9)">
                    <div class="card-content">
                        <div class="card-icon">📅</div>
                        <div class="card-text">
                            <h3>Planning Tools</h3>
                            <p>Access checklists, timelines, and calculators to stay organized throughout the process.</p>
                            <button class="card-button">Access Tools →</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Step 10 -->
            <div class="step-row right">
                <div class="step-number">10</div>
                <div class="step-card" onclick="handleCardClick(10)">
                    <div class="card-content">
                        <div class="card-icon">🚩</div>
                        <div class="card-text">
                            <h3>Next Steps: When You are Ready</h3>
                            <p>Reach your destination. What happens after the sale and planning your next move.</p>
                            <button class="card-button">Final Steps →</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <div class="footer">
        <p class="footer-tagline">Your journey, your pace.</p>
        <div class="footer-links">
            <a href="#privacy">Privacy</a>
            <a href="#terms">Terms</a>
            <a href="#contact">Contact</a>
        </div>
    </div>
    <!-- End Content Wrapper -->

    <script>
        function scrollToJourney() {
            document.getElementById('journey').scrollIntoView({ 
                behavior: 'smooth' 
            });
        }

        function handleCardClick(stepNumber) {
            // Remove active class from all cards
            document.querySelectorAll('.step-card').forEach(card => {
                card.classList.remove('active');
            });

            // Add active class to clicked card
            event.currentTarget.classList.add('active');

            // You can add navigation logic here
            console.log('Step ' + stepNumber + ' clicked');
            
            // Example: Navigate to different pages based on step
            // window.location.href = '/step-' + stepNumber;
            
            // Or open a modal, expand content, etc.
            alert('Opening Step ' + stepNumber + ': ' + event.currentTarget.querySelector('h3').textContent);
        }

        // Optional: Add scroll animation
        window.addEventListener('scroll', function() {
            const cards = document.querySelectorAll('.step-card');
            cards.forEach(card => {
                const cardPosition = card.getBoundingClientRect().top;
                const screenPosition = window.innerHeight / 1.2;
                
                if(cardPosition < screenPosition) {
                    card.style.opacity = '1';
                }
            });
        });
    </script>
</body>
</html>