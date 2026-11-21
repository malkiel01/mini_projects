<?php
/**
 * הרשימות הקבועות של הלשוניות המובנות במסך השוק (spec-stage-a 18.1.4; ש5 — ברירת המחדל: רשימה קבועה בקטלוג,
 * שמתעדכנת ידנית). קוד → שם. מניות — שם החברה כפי שהיא מוכרת (באנגלית); מדדים וסחורות — בעברית.
 *
 * [שלנו] נכתבו ב-2026-11 לפי ההרכב הידוע של המדדים. **הרכב מדד משתנה כמה פעמים בשנה** — עדכון: עורכים כאן ופורסים.
 * סימולי המדדים והסחורות — בצורה של Twelve Data (SPX, XAU/USD). לא נבדקו מול הספק בלי מפתח; סימול שהספק לא מכיר
 * מחזיר מהגרף את השגיאה של הספק, ולא מפיל את הלשונית.
 */

declare(strict_types=1);

/** ₿ קריפטו: 20 הגדולים מול טתר (Binance, בלי מפתח). השמות — ב-TS_PAIR_SIDES (lib/pairs.php) */
const TS_TAB_CRYPTO = ['BTC', 'ETH', 'BNB', 'SOL', 'XRP', 'DOGE', 'ADA', 'TRX', 'AVAX', 'LINK',
    'DOT', 'TON', 'SHIB', 'LTC', 'BCH', 'NEAR', 'UNI', 'XLM', 'ATOM', 'ETC'];

/** 💱 מט"ח: הזוגות העיקריים, וגם דולר ⇄ שקל ואירו ⇄ שקל (ECB בלי מפתח; Twelve Data תוך-יומי עם מפתח) */
const TS_TAB_FX = ['EUR/USD', 'GBP/USD', 'USD/JPY', 'USD/CHF', 'EUR/GBP', 'EUR/JPY', 'USD/ILS', 'EUR/ILS', 'GBP/ILS'];

/** 📊 מדדים: קוד אצל Twelve Data → [שם, מטבע] */
const TS_TAB_INDICES = [
    'SPX' => ['S&P 500', 'USD'], 'NDX' => ['נאסד"ק 100', 'USD'], 'DJI' => ["דאו ג'ונס", 'USD'], 'IXIC' => ['נאסד"ק המורכב', 'USD'],
    'RUT' => ['ראסל 2000', 'USD'], 'VIX' => ['VIX — מדד התנודתיות', 'USD'], 'UKX' => ['פוטסי 100', 'GBP'], 'DAX' => ['דאקס', 'EUR'],
    'CAC' => ['קאק 40', 'EUR'], 'SX5E' => ['יורו סטוקס 50', 'EUR'], 'N225' => ['ניקיי 225', 'JPY'], 'HSI' => ['האנג סנג', 'HKD'],
    'TA35' => ['תל אביב 35', 'ILS'], 'TA125' => ['תל אביב 125', 'ILS'],
];

/** 🛢 סחורות: סימול אצל Twelve Data → שם */
const TS_TAB_COMMODITIES = [
    'XAU/USD' => 'זהב', 'XAG/USD' => 'כסף', 'XPT/USD' => 'פלטינה', 'WTI/USD' => 'נפט WTI', 'XBR/USD' => 'נפט ברנט', 'NG/USD' => 'גז טבעי',
];

/** נאסד"ק 100 */
const TS_TAB_NASDAQ100 = [
    'AAPL' => 'Apple', 'MSFT' => 'Microsoft', 'NVDA' => 'NVIDIA', 'AMZN' => 'Amazon', 'META' => 'Meta Platforms', 'AVGO' => 'Broadcom',
    'GOOGL' => 'Alphabet A', 'GOOG' => 'Alphabet C', 'TSLA' => 'Tesla', 'COST' => 'Costco', 'NFLX' => 'Netflix', 'PLTR' => 'Palantir',
    'AMD' => 'AMD', 'TMUS' => 'T-Mobile US', 'CSCO' => 'Cisco', 'ASML' => 'ASML', 'AZN' => 'AstraZeneca', 'PEP' => 'PepsiCo',
    'LIN' => 'Linde', 'INTU' => 'Intuit', 'ISRG' => 'Intuitive Surgical', 'TXN' => 'Texas Instruments', 'QCOM' => 'Qualcomm',
    'AMGN' => 'Amgen', 'ADBE' => 'Adobe', 'BKNG' => 'Booking Holdings', 'PDD' => 'PDD Holdings', 'AMAT' => 'Applied Materials',
    'HON' => 'Honeywell', 'GILD' => 'Gilead Sciences', 'CMCSA' => 'Comcast', 'PANW' => 'Palo Alto Networks', 'ADP' => 'ADP',
    'MU' => 'Micron', 'LRCX' => 'Lam Research', 'KLAC' => 'KLA', 'APP' => 'AppLovin', 'ADI' => 'Analog Devices', 'MELI' => 'MercadoLibre',
    'CRWD' => 'CrowdStrike', 'SBUX' => 'Starbucks', 'INTC' => 'Intel', 'CEG' => 'Constellation Energy', 'VRTX' => 'Vertex Pharmaceuticals',
    'MSTR' => 'Strategy', 'CDNS' => 'Cadence Design', 'SNPS' => 'Synopsys', 'DASH' => 'DoorDash', 'ORLY' => "O'Reilly Automotive",
    'MAR' => 'Marriott', 'ABNB' => 'Airbnb', 'CTAS' => 'Cintas', 'MDLZ' => 'Mondelez', 'FTNT' => 'Fortinet', 'PYPL' => 'PayPal',
    'REGN' => 'Regeneron', 'WDAY' => 'Workday', 'CSX' => 'CSX', 'ADSK' => 'Autodesk', 'MNST' => 'Monster Beverage', 'ROP' => 'Roper Technologies',
    'AEP' => 'American Electric Power', 'NXPI' => 'NXP Semiconductors', 'CHTR' => 'Charter Communications', 'PCAR' => 'PACCAR',
    'TEAM' => 'Atlassian', 'FAST' => 'Fastenal', 'PAYX' => 'Paychex', 'AXON' => 'Axon Enterprise', 'CPRT' => 'Copart',
    'KDP' => 'Keurig Dr Pepper', 'ROST' => 'Ross Stores', 'DDOG' => 'Datadog', 'EXC' => 'Exelon', 'ZS' => 'Zscaler',
    'FANG' => 'Diamondback Energy', 'CCEP' => 'Coca-Cola Europacific', 'VRSK' => 'Verisk', 'BKR' => 'Baker Hughes', 'XEL' => 'Xcel Energy',
    'CTSH' => 'Cognizant', 'TTWO' => 'Take-Two Interactive', 'IDXX' => 'IDEXX Laboratories', 'EA' => 'Electronic Arts', 'KHC' => 'Kraft Heinz',
    'GEHC' => 'GE HealthCare', 'ODFL' => 'Old Dominion Freight', 'MCHP' => 'Microchip Technology', 'CSGP' => 'CoStar Group',
    'LULU' => 'Lululemon', 'TTD' => 'The Trade Desk', 'DXCM' => 'Dexcom', 'WBD' => 'Warner Bros. Discovery', 'ON' => 'ON Semiconductor',
    'GFS' => 'GlobalFoundries', 'CDW' => 'CDW', 'BIIB' => 'Biogen', 'ARM' => 'Arm Holdings', 'MRVL' => 'Marvell Technology',
    'SHOP' => 'Shopify', 'TRI' => 'Thomson Reuters',
];

/** S&P 500 */
const TS_TAB_SP500 = [
    // טכנולוגיה
    'AAPL' => 'Apple', 'MSFT' => 'Microsoft', 'NVDA' => 'NVIDIA', 'AVGO' => 'Broadcom', 'ORCL' => 'Oracle', 'CRM' => 'Salesforce',
    'CSCO' => 'Cisco', 'ADBE' => 'Adobe', 'ACN' => 'Accenture', 'AMD' => 'AMD', 'IBM' => 'IBM', 'NOW' => 'ServiceNow', 'INTU' => 'Intuit',
    'TXN' => 'Texas Instruments', 'QCOM' => 'Qualcomm', 'AMAT' => 'Applied Materials', 'PLTR' => 'Palantir', 'ANET' => 'Arista Networks',
    'MU' => 'Micron', 'LRCX' => 'Lam Research', 'KLAC' => 'KLA', 'ADI' => 'Analog Devices', 'PANW' => 'Palo Alto Networks', 'INTC' => 'Intel',
    'CRWD' => 'CrowdStrike', 'SNPS' => 'Synopsys', 'CDNS' => 'Cadence Design', 'APH' => 'Amphenol', 'MSI' => 'Motorola Solutions',
    'ADSK' => 'Autodesk', 'FTNT' => 'Fortinet', 'ROP' => 'Roper Technologies', 'WDAY' => 'Workday', 'NXPI' => 'NXP Semiconductors',
    'MCHP' => 'Microchip Technology', 'TEL' => 'TE Connectivity', 'IT' => 'Gartner', 'CTSH' => 'Cognizant', 'GLW' => 'Corning',
    'DELL' => 'Dell Technologies', 'HPQ' => 'HP', 'HPE' => 'Hewlett Packard Enterprise', 'MPWR' => 'Monolithic Power', 'ON' => 'ON Semiconductor',
    'KEYS' => 'Keysight', 'CDW' => 'CDW', 'FICO' => 'Fair Isaac', 'TYL' => 'Tyler Technologies', 'NTAP' => 'NetApp',
    'STX' => 'Seagate', 'WDC' => 'Western Digital', 'PTC' => 'PTC', 'TER' => 'Teradyne', 'ZBRA' => 'Zebra Technologies', 'TDY' => 'Teledyne',
    'FSLR' => 'First Solar', 'SMCI' => 'Super Micro Computer', 'GDDY' => 'GoDaddy', 'VRSN' => 'VeriSign', 'AKAM' => 'Akamai',
    'JBL' => 'Jabil', 'FFIV' => 'F5', 'TRMB' => 'Trimble', 'GEN' => 'Gen Digital', 'SWKS' => 'Skyworks', 'EPAM' => 'EPAM Systems',
    'ENPH' => 'Enphase Energy', 'QRVO' => 'Qorvo', 'DDOG' => 'Datadog', 'APP' => 'AppLovin',
    'TTD' => 'The Trade Desk', 'COIN' => 'Coinbase', 'XYZ' => 'Block',
    // תקשורת
    'GOOGL' => 'Alphabet A', 'GOOG' => 'Alphabet C', 'META' => 'Meta Platforms', 'NFLX' => 'Netflix', 'TMUS' => 'T-Mobile US',
    'DIS' => 'Disney', 'T' => 'AT&T', 'VZ' => 'Verizon', 'CMCSA' => 'Comcast', 'CHTR' => 'Charter Communications', 'EA' => 'Electronic Arts',
    'TTWO' => 'Take-Two Interactive', 'WBD' => 'Warner Bros. Discovery', 'OMC' => 'Omnicom', 'IPG' => 'Interpublic', 'LYV' => 'Live Nation',
    'FOXA' => 'Fox A', 'FOX' => 'Fox B', 'NWSA' => 'News Corp A', 'NWS' => 'News Corp B', 'MTCH' => 'Match Group', 'PARA' => 'Paramount',
    // צריכה מחזורית
    'AMZN' => 'Amazon', 'TSLA' => 'Tesla', 'HD' => 'Home Depot', 'MCD' => "McDonald's", 'BKNG' => 'Booking Holdings', 'LOW' => "Lowe's",
    'TJX' => 'TJX', 'SBUX' => 'Starbucks', 'NKE' => 'Nike', 'ABNB' => 'Airbnb', 'ORLY' => "O'Reilly Automotive", 'MAR' => 'Marriott',
    'CMG' => 'Chipotle', 'HLT' => 'Hilton', 'AZO' => 'AutoZone', 'GM' => 'General Motors', 'F' => 'Ford', 'ROST' => 'Ross Stores',
    'DHI' => 'D.R. Horton', 'LEN' => 'Lennar', 'YUM' => 'Yum! Brands', 'RCL' => 'Royal Caribbean', 'EBAY' => 'eBay', 'TSCO' => 'Tractor Supply',
    'LVS' => 'Las Vegas Sands', 'GRMN' => 'Garmin', 'PHM' => 'PulteGroup', 'NVR' => 'NVR', 'DECK' => 'Deckers', 'ULTA' => 'Ulta Beauty',
    'CCL' => 'Carnival', 'EXPE' => 'Expedia', 'DRI' => 'Darden', 'LULU' => 'Lululemon', 'BBY' => 'Best Buy', 'GPC' => 'Genuine Parts',
    'APTV' => 'Aptiv', 'POOL' => 'Pool', 'DPZ' => "Domino's", 'LKQ' => 'LKQ', 'KMX' => 'CarMax', 'MGM' => 'MGM Resorts', 'WYNN' => 'Wynn Resorts',
    'TPR' => 'Tapestry', 'RL' => 'Ralph Lauren', 'HAS' => 'Hasbro', 'CZR' => 'Caesars', 'NCLH' => 'Norwegian Cruise Line', 'BWA' => 'BorgWarner',
    'MHK' => 'Mohawk', 'DASH' => 'DoorDash', 'UBER' => 'Uber',
    // צריכה בסיסית
    'WMT' => 'Walmart', 'COST' => 'Costco', 'PG' => 'Procter & Gamble', 'KO' => 'Coca-Cola', 'PEP' => 'PepsiCo', 'PM' => 'Philip Morris',
    'MO' => 'Altria', 'MDLZ' => 'Mondelez', 'CL' => 'Colgate-Palmolive', 'TGT' => 'Target', 'KMB' => 'Kimberly-Clark', 'GIS' => 'General Mills',
    'KDP' => 'Keurig Dr Pepper', 'MNST' => 'Monster Beverage', 'STZ' => 'Constellation Brands', 'SYY' => 'Sysco', 'KHC' => 'Kraft Heinz',
    'KR' => 'Kroger', 'HSY' => 'Hershey', 'ADM' => 'Archer-Daniels-Midland', 'KVUE' => 'Kenvue', 'CHD' => 'Church & Dwight',
    'DG' => 'Dollar General', 'DLTR' => 'Dollar Tree', 'EL' => 'Estée Lauder', 'MKC' => 'McCormick', 'CLX' => 'Clorox', 'K' => 'Kellanova',
    'TSN' => 'Tyson Foods', 'CAG' => 'Conagra', 'SJM' => 'J.M. Smucker', 'HRL' => 'Hormel', 'CPB' => "Campbell's", 'BG' => 'Bunge',
    'TAP' => 'Molson Coors', 'BF.B' => 'Brown-Forman', 'LW' => 'Lamb Weston',
    // בריאות
    'LLY' => 'Eli Lilly', 'UNH' => 'UnitedHealth', 'JNJ' => 'Johnson & Johnson', 'ABBV' => 'AbbVie', 'MRK' => 'Merck', 'TMO' => 'Thermo Fisher',
    'ABT' => 'Abbott', 'ISRG' => 'Intuitive Surgical', 'DHR' => 'Danaher', 'AMGN' => 'Amgen', 'PFE' => 'Pfizer', 'BSX' => 'Boston Scientific',
    'SYK' => 'Stryker', 'VRTX' => 'Vertex Pharmaceuticals', 'GILD' => 'Gilead Sciences', 'BMY' => 'Bristol-Myers Squibb', 'MDT' => 'Medtronic',
    'ELV' => 'Elevance Health', 'CI' => 'Cigna', 'REGN' => 'Regeneron', 'ZTS' => 'Zoetis', 'CVS' => 'CVS Health', 'MCK' => 'McKesson',
    'BDX' => 'Becton Dickinson', 'HCA' => 'HCA Healthcare', 'EW' => 'Edwards Lifesciences', 'COR' => 'Cencora', 'IDXX' => 'IDEXX Laboratories',
    'A' => 'Agilent', 'IQV' => 'IQVIA', 'GEHC' => 'GE HealthCare', 'HUM' => 'Humana', 'CNC' => 'Centene', 'RMD' => 'ResMed', 'DXCM' => 'Dexcom',
    'MTD' => 'Mettler-Toledo', 'BIIB' => 'Biogen', 'CAH' => 'Cardinal Health', 'STE' => 'Steris', 'WAT' => 'Waters', 'ZBH' => 'Zimmer Biomet',
    'WST' => 'West Pharmaceutical', 'LH' => 'Labcorp', 'DGX' => 'Quest Diagnostics', 'MOH' => 'Molina Healthcare', 'COO' => 'Cooper Companies',
    'HOLX' => 'Hologic', 'BAX' => 'Baxter', 'PODD' => 'Insulet', 'ALGN' => 'Align Technology', 'RVTY' => 'Revvity', 'MRNA' => 'Moderna',
    'TECH' => 'Bio-Techne', 'INCY' => 'Incyte', 'VTRS' => 'Viatris', 'CRL' => 'Charles River', 'HSIC' => 'Henry Schein', 'UHS' => 'Universal Health Services',
    'SOLV' => 'Solventum', 'DVA' => 'DaVita',
    // פיננסים
    'BRK.B' => 'Berkshire Hathaway', 'JPM' => 'JPMorgan Chase', 'V' => 'Visa', 'MA' => 'Mastercard', 'BAC' => 'Bank of America',
    'WFC' => 'Wells Fargo', 'GS' => 'Goldman Sachs', 'MS' => 'Morgan Stanley', 'AXP' => 'American Express', 'SPGI' => 'S&P Global',
    'BLK' => 'BlackRock', 'C' => 'Citigroup', 'SCHW' => 'Charles Schwab', 'PGR' => 'Progressive', 'CB' => 'Chubb', 'MMC' => 'Marsh McLennan',
    'BX' => 'Blackstone', 'KKR' => 'KKR', 'ICE' => 'Intercontinental Exchange', 'CME' => 'CME Group', 'MCO' => "Moody's", 'PYPL' => 'PayPal',
    'AON' => 'Aon', 'USB' => 'U.S. Bancorp', 'PNC' => 'PNC Financial', 'COF' => 'Capital One', 'TFC' => 'Truist', 'AJG' => 'Arthur J. Gallagher',
    'AFL' => 'Aflac', 'TRV' => 'Travelers', 'ALL' => 'Allstate', 'MET' => 'MetLife', 'AIG' => 'AIG', 'PRU' => 'Prudential', 'BK' => 'BNY',
    'FIS' => 'Fidelity National Info', 'MSCI' => 'MSCI', 'AMP' => 'Ameriprise', 'APO' => 'Apollo Global', 'ACGL' => 'Arch Capital',
    'HIG' => 'Hartford', 'MTB' => 'M&T Bank', 'FITB' => 'Fifth Third', 'STT' => 'State Street',
    'RJF' => 'Raymond James', 'NDAQ' => 'Nasdaq', 'WTW' => 'Willis Towers Watson', 'BRO' => 'Brown & Brown', 'GPN' => 'Global Payments',
    'HBAN' => 'Huntington Bancshares', 'RF' => 'Regions Financial', 'CFG' => 'Citizens Financial', 'SYF' => 'Synchrony', 'NTRS' => 'Northern Trust',
    'CINF' => 'Cincinnati Financial', 'CBOE' => 'Cboe Global Markets', 'KEY' => 'KeyCorp', 'PFG' => 'Principal Financial', 'WRB' => 'W. R. Berkley',
    'L' => 'Loews', 'EG' => 'Everest Group', 'CPAY' => 'Corpay', 'JKHY' => 'Jack Henry', 'TROW' => 'T. Rowe Price', 'BEN' => 'Franklin Resources',
    'IVZ' => 'Invesco', 'AIZ' => 'Assurant', 'GL' => 'Globe Life', 'ERIE' => 'Erie Indemnity', 'FDS' => 'FactSet', 'MKTX' => 'MarketAxess',
    'IBKR' => 'Interactive Brokers', 'HOOD' => 'Robinhood',
    // תעשייה
    'GE' => 'GE Aerospace', 'CAT' => 'Caterpillar', 'RTX' => 'RTX', 'HON' => 'Honeywell', 'UNP' => 'Union Pacific', 'BA' => 'Boeing',
    'DE' => 'Deere', 'LMT' => 'Lockheed Martin', 'ETN' => 'Eaton', 'ADP' => 'ADP', 'UPS' => 'UPS', 'GEV' => 'GE Vernova', 'PH' => 'Parker-Hannifin',
    'TT' => 'Trane Technologies', 'WM' => 'Waste Management', 'GD' => 'General Dynamics', 'NOC' => 'Northrop Grumman', 'ITW' => 'Illinois Tool Works',
    'MMM' => '3M', 'TDG' => 'TransDigm', 'CTAS' => 'Cintas', 'EMR' => 'Emerson', 'CSX' => 'CSX', 'FDX' => 'FedEx', 'NSC' => 'Norfolk Southern',
    'CARR' => 'Carrier', 'PCAR' => 'PACCAR', 'JCI' => 'Johnson Controls', 'RSG' => 'Republic Services', 'URI' => 'United Rentals',
    'CPRT' => 'Copart', 'GWW' => 'W.W. Grainger', 'PAYX' => 'Paychex', 'AME' => 'AMETEK', 'CMI' => 'Cummins', 'FAST' => 'Fastenal',
    'PWR' => 'Quanta Services', 'OTIS' => 'Otis', 'HWM' => 'Howmet Aerospace', 'LHX' => 'L3Harris', 'AXON' => 'Axon Enterprise',
    'VRSK' => 'Verisk', 'ODFL' => 'Old Dominion Freight', 'IR' => 'Ingersoll Rand', 'EFX' => 'Equifax', 'XYL' => 'Xylem', 'ROK' => 'Rockwell Automation',
    'DAL' => 'Delta Air Lines', 'UAL' => 'United Airlines', 'LUV' => 'Southwest Airlines', 'WAB' => 'Wabtec', 'DOV' => 'Dover', 'FTV' => 'Fortive',
    'BR' => 'Broadridge', 'VLTO' => 'Veralto', 'HUBB' => 'Hubbell', 'LDOS' => 'Leidos', 'BLDR' => 'Builders FirstSource', 'EXPD' => 'Expeditors',
    'J' => 'Jacobs Solutions', 'TXT' => 'Textron', 'SNA' => 'Snap-on', 'PNR' => 'Pentair', 'IEX' => 'IDEX', 'MAS' => 'Masco', 'SWK' => 'Stanley Black & Decker',
    'JBHT' => 'J.B. Hunt', 'CHRW' => 'C.H. Robinson', 'ROL' => 'Rollins', 'NDSN' => 'Nordson', 'ALLE' => 'Allegion', 'AOS' => 'A. O. Smith',
    'GNRC' => 'Generac', 'HII' => 'Huntington Ingalls', 'DAY' => 'Dayforce', 'PAYC' => 'Paycom', 'AMTM' => 'Amentum', 'LII' => 'Lennox',
    'EME' => 'EMCOR',
    // אנרגיה
    'XOM' => 'Exxon Mobil', 'CVX' => 'Chevron', 'COP' => 'ConocoPhillips', 'EOG' => 'EOG Resources', 'WMB' => 'Williams', 'SLB' => 'SLB',
    'OKE' => 'ONEOK', 'MPC' => 'Marathon Petroleum', 'PSX' => 'Phillips 66', 'KMI' => 'Kinder Morgan', 'VLO' => 'Valero', 'OXY' => 'Occidental',
    'FANG' => 'Diamondback Energy', 'BKR' => 'Baker Hughes', 'TRGP' => 'Targa Resources', 'EQT' => 'EQT', 'DVN' => 'Devon Energy',
    'HAL' => 'Halliburton', 'CTRA' => 'Coterra', 'EXE' => 'Expand Energy', 'TPL' => 'Texas Pacific Land', 'APA' => 'APA',
    // חומרים
    'LIN' => 'Linde', 'SHW' => 'Sherwin-Williams', 'APD' => 'Air Products', 'ECL' => 'Ecolab', 'FCX' => 'Freeport-McMoRan', 'NEM' => 'Newmont',
    'CTVA' => 'Corteva', 'DOW' => 'Dow', 'DD' => 'DuPont', 'NUE' => 'Nucor', 'PPG' => 'PPG', 'MLM' => 'Martin Marietta', 'VMC' => 'Vulcan Materials',
    'IFF' => 'International Flavors', 'LYB' => 'LyondellBasell', 'SW' => 'Smurfit Westrock', 'PKG' => 'Packaging Corp', 'BALL' => 'Ball',
    'STLD' => 'Steel Dynamics', 'IP' => 'International Paper', 'AVY' => 'Avery Dennison', 'AMCR' => 'Amcor', 'CF' => 'CF Industries',
    'MOS' => 'Mosaic', 'ALB' => 'Albemarle', 'CE' => 'Celanese', 'EMN' => 'Eastman Chemical',
    // תשתיות
    'NEE' => 'NextEra Energy', 'SO' => 'Southern Company', 'DUK' => 'Duke Energy', 'CEG' => 'Constellation Energy', 'AEP' => 'American Electric Power',
    'SRE' => 'Sempra', 'D' => 'Dominion Energy', 'VST' => 'Vistra', 'EXC' => 'Exelon', 'PCG' => 'PG&E', 'XEL' => 'Xcel Energy', 'PEG' => 'PSEG',
    'ED' => 'Consolidated Edison', 'ETR' => 'Entergy', 'WEC' => 'WEC Energy', 'EIX' => 'Edison International', 'DTE' => 'DTE Energy',
    'AEE' => 'Ameren', 'PPL' => 'PPL', 'ATO' => 'Atmos Energy', 'ES' => 'Eversource', 'FE' => 'FirstEnergy', 'CNP' => 'CenterPoint',
    'CMS' => 'CMS Energy', 'NRG' => 'NRG Energy', 'NI' => 'NiSource', 'LNT' => 'Alliant Energy', 'EVRG' => 'Evergy', 'AWK' => 'American Water Works',
    'PNW' => 'Pinnacle West', 'AES' => 'AES',
    // נדל"ן
    'PLD' => 'Prologis', 'AMT' => 'American Tower', 'WELL' => 'Welltower', 'EQIX' => 'Equinix', 'SPG' => 'Simon Property', 'O' => 'Realty Income',
    'PSA' => 'Public Storage', 'DLR' => 'Digital Realty', 'CCI' => 'Crown Castle', 'CBRE' => 'CBRE', 'VICI' => 'VICI Properties',
    'EXR' => 'Extra Space Storage', 'AVB' => 'AvalonBay', 'IRM' => 'Iron Mountain', 'VTR' => 'Ventas', 'EQR' => 'Equity Residential',
    'CSGP' => 'CoStar Group', 'SBAC' => 'SBA Communications', 'WY' => 'Weyerhaeuser', 'INVH' => 'Invitation Homes', 'MAA' => 'Mid-America Apartment',
    'ESS' => 'Essex Property', 'ARE' => 'Alexandria Real Estate', 'KIM' => 'Kimco Realty', 'DOC' => 'Healthpeak', 'UDR' => 'UDR',
    'HST' => 'Host Hotels', 'CPT' => 'Camden Property', 'REG' => 'Regency Centers', 'BXP' => 'BXP', 'FRT' => 'Federal Realty',
];
