# MRS 설치 안내 (PHP 웹호스팅용)

PMS 파트관리시스템과 같은 웹호스팅(PHP + MariaDB)에 올려 `https://www.datanex.io.kr/mrs` 로 접속하는 버전입니다.
Python·composer 가 필요 없고, FTP 로 올린 뒤 설치 화면에서 DB 정보만 입력하면 됩니다.

## 1. 서버 요구 사항
- PHP 7.4 이상 (8.x 권장)
- 확장: `pdo_mysql`, `mbstring`, `zip`(PPT·엑셀 생성), `openssl`(암호화·메일 보안 연결), `curl`(Claude AI 호출, 없으면 규칙 기반 초안만 사용)
- MariaDB 10.x / MySQL 5.7 이상 (PMS 와 같은 DB `datanex8` 사용)
- 대용량 로그를 올리려면 `upload_max_filesize`, `post_max_size` 를 50M 이상으로 늘려 주세요.

## 2. 올리기
1. 이 `mrs` 폴더 전체를 FTP 로 `www/mrs/` 에 올립니다.
2. 폴더 권한은 755, 파일 권한은 644 로 둡니다. 707·777 처럼 다른 사용자에게 쓰기를 열면 호스팅에 따라 "Internal Server Error"가 날 수 있습니다. 설치 시 `config.php` 와 `data/` 폴더를 자동으로 만듭니다.

## 3. 설치
1. 브라우저에서 `https://www.datanex.io.kr/mrs/install.php` 를 엽니다.
2. 서버 환경 점검이 모두 "정상"인지 확인합니다.
3. DB 정보(호스트·포트·DB 이름 `datanex8`·사용자 `datanex8`·비밀번호)와 최초 관리자 계정을 입력하고 **설치 시작**을 누릅니다.
   - 기존 `pms_` 테이블은 건드리지 않고 `mrs_` 로 시작하는 테이블 13개만 추가합니다. (참고용 DDL: `database.sql`)
4. 설치가 끝나면 **서버에서 `install.php` 를 삭제**합니다.

## 4. 처음 설정 ([시스템 설정] 화면, 관리자만)
- **회사 정보**: 회사명, 주소. 보고서의 연락처·이메일은 각 보고서 **담당 엔지니어의 사용자 정보**에서 가져옵니다.
- **메일 발송(SMTP)**: 서버, 포트, 보안 연결(STARTTLS/SSL/없음), 계정, 비밀번호, 보내는 사람. [테스트 메일 보내기]로 확인합니다.
- **Claude AI**: Anthropic API 키. Claude Console(platform.claude.com) → API Keys 에서 발급하며, 사용한 만큼 과금됩니다.
- **기존 고객 DB 연동**: 기본 `pms_customers`. [고객] 화면에서 PMS 고객을 불러오면 담당자1·2가 전산담당자로 함께 들어옵니다. (읽기 전용)
- SMTP 비밀번호와 API 키는 `config.php` 의 secret 으로 암호화해 `mrs_setting` 테이블에 저장합니다.

## 5. 사용자 · 권한
[사용자 · 권한] 화면에서 엔지니어를 등록합니다. 이메일·연락처를 꼭 입력하세요. 보고서 표지·서명란·메일 서명에 그대로 쓰입니다.
역할(관리자/매니저/엔지니어/조회자) 기본 권한에 더해, 사용자별로 권한을 켜고 끌 수 있습니다.

## 6. PC에 설치 (MRS 아이콘)
Chrome/Edge 에서 접속 후 상단 **[내 PC에 MRS 설치]** 를 누르면 바탕화면·시작 메뉴에 MRS 아이콘이 생깁니다.

## 7. 보안 메모
- `config.php`(DB 비밀번호·암호화 키)와 `data/`(로그·보고서 파일)는 `.htaccess` 로 외부 접근을 막습니다. 절대 Git 에 올리지 마세요.
- `config.php` 의 secret 을 바꾸면 저장된 SMTP 비밀번호·API 키를 다시 입력해야 합니다.
- 다시 설치하려면 `config.php` 를 지우고 `install.php` 를 다시 올립니다. (기존 `mrs_` 데이터는 유지됩니다.)

## 8. 백업
- DB: `mrs_` 테이블 (PMS 백업과 함께 덤프)
- 파일: `mrs/data/` 폴더 (업로드 로그, 생성된 보고서, 고객 양식)
