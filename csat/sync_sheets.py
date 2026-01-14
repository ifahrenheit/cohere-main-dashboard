#!/usr/bin/env python3
"""
Google Sheets to MySQL Sync Script
Location: /var/www/html/cohere_dashboard/csat/sync_sheets.py
"""

import os
from datetime import datetime
from google.oauth2 import service_account
from googleapiclient.discovery import build
import pymysql

# Configuration
SHEET_ID = '1-hIS_DvG7bxjHPNaoq8LLlQ7vwID3KtCDdVN5PhvLxw'
CREDENTIALS_FILE = '/var/www/html/cohere_dashboard/csat/backend/credentials.json'
SHEET_RANGE = 'Imported!A:N'

# MySQL Configuration
DB_HOST = 'localhost'
DB_USER = 'root'
DB_PASSWORD = 'Rootpass123!@#'
DB_NAME = 'central_db'

# START FROM ROW 3 (skip row 1 identifier, row 2 is header)
HEADER_ROW = 1  # Row 2 in sheet (index 1)
DATA_START_ROW = 2  # Row 3 in sheet (index 2)

def log(message):
    """Print with timestamp"""
    timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
    print(f"[{timestamp}] {message}")

def download_sheet(sheet_id, credentials_file, range_name):
    """Download data from Google Sheets"""
    log("🔄 Connecting to Google Sheets...")
    
    try:
        credentials = service_account.Credentials.from_service_account_file(
            credentials_file,
            scopes=['https://www.googleapis.com/auth/spreadsheets.readonly']
        )
        
        service = build('sheets', 'v4', credentials=credentials)
        sheet = service.spreadsheets()
        
        log(f"📥 Downloading data from sheet: {sheet_id}")
        result = sheet.values().get(
            spreadsheetId=sheet_id,
            range=range_name
        ).execute()
        
        values = result.get('values', [])
        
        if not values:
            log("⚠️  No data found in sheet!")
            return None
        
        log(f"✅ Downloaded {len(values)} rows from sheet")
        return values
        
    except FileNotFoundError:
        log(f"❌ Credentials file not found: {credentials_file}")
        return None
    except Exception as e:
        log(f"❌ Error downloading sheet: {e}")
        return None

def parse_date(date_str):
    """Parse date from various formats"""
    if not date_str:
        return None
    
    formats = ['%m/%d/%Y', '%Y-%m-%d', '%d/%m/%Y', '%Y/%m/%d']
    for fmt in formats:
        try:
            return datetime.strptime(date_str, fmt).strftime('%Y-%m-%d')
        except ValueError:
            continue
    
    return None

def import_to_database(data):
    """Import data to MySQL"""
    log("📊 Importing to database...")
    
    conn = None
    cursor = None
    
    try:
        # Connect to MySQL using PyMySQL
        conn = pymysql.connect(
            host=DB_HOST,
            user=DB_USER,
            password=DB_PASSWORD,
            database=DB_NAME,
            charset='utf8mb4',
            cursorclass=pymysql.cursors.DictCursor
        )
        
        cursor = conn.cursor()
        log("✅ Connected to MySQL database")
        
        # Check minimum rows
        if len(data) < DATA_START_ROW + 1:
            log("❌ Not enough rows in sheet!")
            return False
        
        # Row 1 (index 0): Identifier - SKIP
        log(f"⏭️  Skipping row 1: {data[0][:3] if data[0] else 'empty'}...")
        
        # Row 2 (index 1): Headers
        header = data[HEADER_ROW]
        log(f"📋 Header row (row 2): {header[:5]}...")
        
        # Row 3+ (index 2+): Data
        rows = data[DATA_START_ROW:]
        log(f"📝 Processing {len(rows)} data rows (starting from row 3)...")
        
        # Create header mapping
        header_map = {col.strip(): idx for idx, col in enumerate(header)}
        
        imported = 0
        skipped = 0
        
        for row_num, row in enumerate(rows, start=3):  # Start counting from row 3
            try:
                # Skip empty rows
                if not row or len(row) < 5:
                    skipped += 1
                    continue
                
                # Get values by column name
                def get_val(col_name, default=''):
                    idx = header_map.get(col_name, -1)
                    if idx >= 0 and idx < len(row):
                        val = row[idx]
                        return val.strip() if isinstance(val, str) else str(val) if val else default
                    return default
                
                # Extract all fields
                ticket_number = get_val('Ticket ID')
                agent_name = get_val('Name')
                agent_email = get_val('Agent Email')
                team_lead = get_val('TL')
                theme = get_val('Theme [L1]') or get_val('Theme')
                survey_date = parse_date(get_val('Performed at Date'))
                channel_type = get_val('Channel Type') or get_val('Channel')
                sentiment = get_val('Sentiment')
                
                # CSAT score
                csat_str = get_val('CSAT Rate') or get_val('CSAT')
                try:
                    csat_score = int(float(csat_str))
                    if csat_score < 1 or csat_score > 5:
                        skipped += 1
                        continue
                except:
                    skipped += 1
                    continue
                
                # Validate required fields
                if not ticket_number or not survey_date or not agent_name:
                    skipped += 1
                    continue
                
                # Insert or update (DON'T include csat_type - it's auto-generated!)
                insert_query = """
                    INSERT INTO csat_scores 
                    (ticket_number, agent_name, agent_email, team_lead, theme, 
                     survey_date, csat_score, channel_type, sentiment)
                    VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s)
                    ON DUPLICATE KEY UPDATE
                        agent_name = VALUES(agent_name),
                        agent_email = VALUES(agent_email),
                        team_lead = VALUES(team_lead),
                        theme = VALUES(theme),
                        survey_date = VALUES(survey_date),
                        csat_score = VALUES(csat_score),
                        channel_type = VALUES(channel_type),
                        sentiment = VALUES(sentiment)
                """
                
                cursor.execute(insert_query, (
                    ticket_number, agent_name, agent_email, team_lead, theme,
                    survey_date, csat_score, channel_type, sentiment
                ))
                
                imported += 1
                
                # Commit every 1000 rows
                if imported % 1000 == 0:
                    conn.commit()
                    log(f"  ✅ Imported {imported:,} rows...")
            
            except Exception as e:
                log(f"  Warning: Error on row {row_num}: {e}")
                skipped += 1
                continue
        
        # Final commit
        conn.commit()
        
        # Get totals
        cursor.execute("SELECT COUNT(*) as total FROM csat_scores")
        total_records = cursor.fetchone()['total']
        
        cursor.execute("SELECT COUNT(*) as csat FROM csat_scores WHERE csat_type = 'CSAT'")
        csat_count = cursor.fetchone()['csat']
        
        cursor.execute("SELECT COUNT(*) as dsat FROM csat_scores WHERE csat_type = 'DSAT'")
        dsat_count = cursor.fetchone()['dsat']
        
        log("")
        log("✅ Import complete!")
        log(f"  Imported: {imported:,}")
        log(f"  Skipped: {skipped:,}")
        log(f"  Total records in database: {total_records:,}")
        log(f"  CSAT (4-5): {csat_count:,}")
        log(f"  DSAT (1-3): {dsat_count:,}")
        
        return True
        
    except Exception as e:
        log(f"❌ Database error: {e}")
        import traceback
        log(traceback.format_exc())
        return False
    finally:
        if cursor:
            cursor.close()
        if conn:
            conn.close()
            log("🔌 MySQL connection closed")

def main():
    """Main sync function"""
    log("=" * 70)
    log("🔄 Google Sheets → MySQL Sync")
    log("=" * 70)
    log(f"Started: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    log("")
    
    # Check credentials file
    if not os.path.exists(CREDENTIALS_FILE):
        log(f"❌ Error: Credentials file not found: {CREDENTIALS_FILE}")
        return False
    
    log(f"📍 Sheet ID: {SHEET_ID}")
    log(f"📍 Range: {SHEET_RANGE}")
    log(f"📍 Database: {DB_NAME}")
    log(f"📍 Header row: {HEADER_ROW + 1} (row 2 in sheet)")
    log(f"📍 Data starts: {DATA_START_ROW + 1} (row 3 in sheet)")
    log("")
    
    # Download from Google Sheets
    data = download_sheet(SHEET_ID, CREDENTIALS_FILE, SHEET_RANGE)
    if not data:
        return False
    
    # Import to database
    if not import_to_database(data):
        return False
    
    log("")
    log("=" * 70)
    log("✅ Sync Complete!")
    log(f"Finished: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    log("=" * 70)
    
    return True

if __name__ == '__main__':
    success = main()
    exit(0 if success else 1)