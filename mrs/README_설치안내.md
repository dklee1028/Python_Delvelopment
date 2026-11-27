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
- **메일 발송**: 발송 방식을 고릅니다.
  - **일반 SMTP**: 서버, 포트, 보안 연결(STARTTLS/SSL/없음), 계정, 비밀번호.
  - **Microsoft 365 (OAuth)**: 회사 M365에 "보안 기본값"이 켜져 SMTP 로그인이 막힐 때(`535 5.7.139`) 씁니다. 보안 정책은 그대로 두고 Graph API로 보냅니다. 아래 **4-1** 의 Azure 앱 등록이 필요합니다.
  - 어느 방식이든 [저장] 후 [테스트 메일 보내기]로 확인합니다.
- **Claude AI**: Anthropic API 키. Claude Console(platform.claude.com) → API Keys 에서 발급하며, 사용한 만큼 과금됩니다.
- **기존 고객 DB 연동**: 기본 `pms_customers`. [고객] 화면에서 PMS 고객을 불러오면 담당자1·2가 전산담당자로 함께 들어옵니다. (읽기 전용)
- SMTP 비밀번호, M365 클라이언트 암호, API 키는 `config.php` 의 secret 으로 암호화해 `mrs_setting` 테이블에 저장합니다.

## 4-1. Microsoft 365 메일 발송 준비 (Azure 앱 등록, 처음 한 번)
M365 **전역 관리자** 계정으로 진행합니다. 약 10분 걸립니다.

1. https://portal.azure.com 접속 → 위쪽 검색창에 **Microsoft Entra ID** 입력 → 들어가기.
2. 왼쪽 **앱 등록** → **+ 새 등록**.
   - 이름: `MRS 메일 발송` (아무 이름이나 가능)
   - 지원되는 계정 유형: **이 조직 디렉터리의 계정만**
   - 리디렉션 URI: 비워 둠 → **등록**
3. 만들어진 앱의 **개요** 화면에서 두 값을 메모장에 복사합니다.
   - **애플리케이션(클라이언트) ID**
   - **디렉터리(테넌트) ID**
4. 왼쪽 **인증서 및 비밀** → **+ 새 클라이언트 암호** → 설명 `MRS`, 만료 **24개월** → 추가.
   - 표에 나온 **값**을 바로 복사합니다. (화면을 벗어나면 다시 볼 수 없습니다. "비밀 ID"가 아니라 **값**입니다.)
   - 만료일을 달력에 적어 두세요. 만료되면 새 암호를 만들어 MRS에 다시 넣어야 합니다.
5. 왼쪽 **API 사용 권한** → **+ 권한 추가** → **Microsoft Graph** → **애플리케이션 권한** → 검색창에 `Mail.Send` → 체크 → **권한 추가**.
   - 이어서 **"(회사명)에 대한 관리자 동의 허용"** 버튼 → 예. 상태가 초록색 **허용됨**이 되어야 합니다.
6. (권장) 이 앱이 **보고서 발송용 메일함 하나로만** 보내도록 제한합니다. 그대로 두면 회사의 모든 메일함 이름으로 보낼 수 있는 권한입니다.
   Exchange Online PowerShell 에서:
   ```powershell
   Connect-ExchangeOnline
   New-DistributionGroup -Name "MRS 발송 허용" -Type Security -Members report@회사도메인
   New-ApplicationAccessPolicy -AppId <클라이언트 ID> -PolicyScopeGroupId "MRS 발송 허용" -AccessRight RestrictAccess -Description "MRS는 report 메일함만"
   ```
7. MRS [시스템 설정] → **발송 방식: Microsoft 365** → 테넌트 ID, 클라이언트 ID, 클라이언트 암호(값) 입력.
   **보내는 사람 주소**에는 실제 메일함 주소(예: `report@회사도메인`, Exchange 라이선스 필요)를 넣고 [저장] → [테스트 메일 보내기].

자주 나는 오류
- `AADSTS7000215` 클라이언트 암호가 틀림 → **값**을 다시 복사해 넣기
- `AADSTS7000222` 암호 만료 → 4번을 다시 해서 새 암호 입력
- `403 ErrorAccessDenied` → 5번의 관리자 동의가 안 됐거나, 6번 정책의 허용 그룹에 보내는 주소가 없음
- `404 / ErrorInvalidUser` → 보내는 사람 주소가 실제 메일함이 아님
- 첨부 합계 약 2.8MB 초과 시 이 방식으로는 보낼 수 없습니다. (보고서는 보통 100KB 안팎)

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
