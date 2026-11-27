# MRS · Management Report System

DATANEX 엔지니어가 **고객 선택 → 보고서 종류 선택 → 로그 끌어다 놓기**만 하면, 로그를 분석해 컨설턴트 수준의 **PPT + 엑셀 보고서**를 만들고 고객 담당자에게 메일로 보내는 시스템입니다.
서비스 주소: `https://www.datanex.io.kr/mms`

## 주요 기능 (프로젝트 지침 대응표)

| # | 요구사항 | 구현 |
|---|---|---|
| 1, 13 | 기존 MariaDB 공유, 고객명·주소 공유, **테이블 추가만** | `mrs_` 접두어 테이블 12개만 생성(`sql/mrs_schema_mariadb.sql`). 기존 고객 테이블은 `.env` 매핑으로 **SELECT만** 수행 (`app/legacy.py`) |
| 2 | 전산담당 고객 등록·수정·삭제, 기존 DB와 연결 | 고객 › 🔗 기존 DB 검색 후 연결(동기화 지원) 또는 MRS 단독 등록. 고객별 **전산 담당자** CRUD |
| 3 | 고객 → 보고서 종류 → 로그 → 보고서 | 4단계 생성 마법사 (`#/new`) |
| 4 | 로그 드래그&드롭, 장비별·시점별 보관/조회 | `data/logs/c{고객}/e{장비}/{YYYYMMDD_HHMM}/` 보관, 시리얼·호스트명 자동 매칭, 장비·기간 필터 타임라인 |
| 5 | 장비 1대 / 2~3대 / 4대+ 3종 양식 | `single`(1대 상세: 건강점수 게이지·용량차트) / `multi`(개요+장비별 1p+종합) / `fleet`(전체 대시보드+2대/1p+매트릭스) 자동 선택 |
| 6 | Claude 조언 → 엔지니어 검토·수정 **(필수)** | AI 조언을 받지 않거나 "검토 확정"을 체크하지 않으면 보고서 생성 불가 |
| 7 | 육안점검 최종 선택·엔지니어 최종 Comment **(필수)** | 생성 후 전 장비·전 항목(12개) 선택 + Comment 입력해야 최종본 확정 |
| 8 | 정기/특별/작업계획서(OS·컨트롤러·디스크·장애처리 등)/고객 양식 | 작업계획서 8종(기본 절차·롤백 자동 채움, 표에서 편집). 고객 양식(pptx/xlsx)에 `{{치환자}}` 자동 채움 |
| 9 | 완성 보고서 관리 | 보고서 관리 목록(검색·필터)·수정(재분석)·삭제, 상태(작성/검토/완료/발송) |
| 10 | 전문 컨설턴트 분석, 심플·가독성 | 로그 기반 진단 규칙(용량·디스크·HA·포트·이벤트·EOS OS) + 건강점수, DATANEX 브랜드 컬러 |
| 11 | 시스템에서 고객 담당자 메일로 첨부 발송 | SMTP 발송, 담당자 자동 선택, 발송 이력 |
| 12 | 최초 접속 시 로컬 설치, MRS 아이콘 | PWA (manifest + service worker). 상단 "⬇ 내 PC에 MRS 설치" 버튼 → 바탕화면/시작메뉴에 MRS 아이콘 |
| 14 | `/mms` 경로 | 앱이 `/mms` 하위에 마운트됨, nginx 설정 예시 제공 |
| 15 | 사용자별 권한, 등록·삭제 | 역할(관리자/팀장/엔지니어/조회자) + 12개 세부 권한 개별 조정, 감사 로그 |
| 16 | 다이나믹 | 카운트업·게이지 애니메이션, 단계형 마법사, 드래그&드롭, 완료 시 컨페티, 다크모드 |
| 17 | PPT·엑셀 항상 두 가지 | 모든 보고서에서 표준 PPT + 엑셀 동시 생성 (고객 양식은 추가로 생성) |

## 보고서 흐름

```
로그 분석 → ② Claude AI 진단 조언 → ③ 엔지니어 검토·확정 → ④ 검토본 생성(PPT+엑셀)
        → ⑤ 육안점검 최종 선택 + 최종 Comment → 최종본 확정 → ⑥ 고객 담당자 메일 발송
```

## 설치 (서버)

```bash
sudo mkdir -p /opt/mrs && sudo cp -r mrs/* /opt/mrs/ && cd /opt/mrs
python3 -m venv .venv && .venv/bin/pip install -r requirements.txt
cp .env.example .env    # DB 접속정보, 기존 고객 테이블 매핑, SMTP, ANTHROPIC_API_KEY 입력
# (선택) DBA 사전 검토: sql/mrs_schema_mariadb.sql — 없으면 최초 기동 시 mrs_ 테이블만 자동 생성
sudo cp deploy/mrs.service /etc/systemd/system/ && sudo systemctl enable --now mrs
# nginx: deploy/nginx-mms.conf 내용을 www.datanex.io.kr server 블록에 추가 후 reload
```

최초 관리자: `.env` 의 `MRS_ADMIN_USERNAME` / `MRS_ADMIN_PASSWORD` (DB에 사용자가 하나도 없을 때 1회 생성). 로그인 후 비밀번호를 바꾸세요.

### 로컬 개발

```bash
cd mrs && pip install -r requirements.txt
uvicorn app.main:app --reload --port 8000     # http://localhost:8000/mms/  (기본: SQLite ./data/mrs_dev.db)
pytest -q                                      # 3종 양식·작업계획서·고객양식·권한·PWA 테스트
```

## 로그 파서 지원 범위

NetApp ONTAP 7-Mode / Cluster-Mode CLI 출력(.txt/.log/.out, .zip/.tar.gz/.gz 묶음):
`version`, `sysconfig -a`, `system node show`, `storage aggregate show`, `df -A(h)`, `df -h`, `df -i`, `volume show`,
`vol status -f/-s`, `storage disk show -broken/-container-type spare`, `storage failover show`, `cf status`,
`network port show`, `event log show`, `/etc/messages`, `autosupport`. 형식을 모르는 로그도 이벤트 키워드 스캔은 수행합니다.
`sample_logs/` 에 예시가 있습니다.

## 구조

```
app/
  main.py            /mms 마운트, PWA manifest·service worker
  models.py          mrs_* 테이블 (SQLAlchemy)
  legacy.py          기존 고객 테이블 읽기 전용 조회
  auth.py            로그인 세션·역할·세부 권한
  routers/           customers, logs, reports, templates, auth_users
  services/
    log_parser.py    로그 → 구조화 데이터
    analyzer.py      진단(Findings)·건강점수·판정
    ai_advisor.py    Claude(claude-opus-5-5) 조언, 실패/미설정 시 규칙 기반 초안
    pptx_report.py   3종 점검보고서 + 작업계획서 PPT
    xlsx_report.py   엑셀 버전
    custom_template.py  고객 양식 {{치환자}} 채움
    mailer.py        SMTP 첨부 발송
  static/            SPA (index.html, app.js, app.css), 아이콘, 로고
sql/mrs_schema_mariadb.sql   MariaDB DDL (테이블 추가만)
deploy/                     nginx, systemd 예시
```
