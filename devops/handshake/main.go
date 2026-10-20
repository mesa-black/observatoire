// handshake tells a reader's browser which TLS key exchange its own
// connection just negotiated: the one fact no page can read from JavaScript.
// It terminates TLS itself, behind a Traefik route that passes the connection
// through untouched, and answers one line of JSON. Nothing is logged and
// nothing is kept: the answer is the reader's own handshake, sent back to them.
package main

import (
	"crypto/tls"
	"encoding/json"
	"log"
	"net/http"
	"os"
	"sync"
	"time"
)

// report answers with what this connection negotiated. Only the observatoire's
// own pages may read it (ALLOW_ORIGIN), and never from a cache.
func report(origin string) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodGet {
			http.Error(w, "GET only", http.StatusMethodNotAllowed)
			return
		}
		if origin != "" {
			w.Header().Set("Access-Control-Allow-Origin", origin)
			w.Header().Set("Vary", "Origin")
		}
		w.Header().Set("Cache-Control", "no-store")
		w.Header().Set("Content-Type", "application/json")
		w.Header().Set("X-Content-Type-Options", "nosniff")
		// Traefik passes the TLS through untouched, so the headers it adds to
		// the other sites never reach this one: HSTS is set here.
		w.Header().Set("Strict-Transport-Security", "max-age=31536000; includeSubDomains")
		cs := r.TLS
		if cs == nil {
			http.Error(w, "TLS only", http.StatusBadRequest)
			return
		}
		_ = json.NewEncoder(w).Encode(map[string]any{
			"group":   cs.CurveID.String(),
			"hybrid":  cs.CurveID == tls.X25519MLKEM768,
			"version": tls.VersionName(cs.Version),
			"cipher":  tls.CipherSuiteName(cs.CipherSuite),
		})
	}
}

// keyPair reloads the certificate when cert-manager renews it (the mounted
// Secret's files change every two months): without this, the first one would
// expire in the running process after ninety days.
type keyPair struct {
	certFile, keyFile string
	mu                sync.Mutex
	loaded            time.Time
	cert              *tls.Certificate
}

func (k *keyPair) get(*tls.ClientHelloInfo) (*tls.Certificate, error) {
	st, err := os.Stat(k.certFile)
	k.mu.Lock()
	defer k.mu.Unlock()
	if err == nil && (k.cert == nil || st.ModTime().After(k.loaded)) {
		if c, err := tls.LoadX509KeyPair(k.certFile, k.keyFile); err == nil {
			k.cert, k.loaded = &c, st.ModTime()
		} else if k.cert == nil {
			return nil, err
		}
	}
	if k.cert == nil {
		return nil, err
	}
	return k.cert, nil
}

func main() {
	kp := &keyPair{certFile: os.Getenv("TLS_CERT"), keyFile: os.Getenv("TLS_KEY")}
	if _, err := kp.get(nil); err != nil {
		log.Fatalf("certificate: %v", err)
	}
	srv := &http.Server{
		Addr:    ":8443",
		Handler: report(os.Getenv("ALLOW_ORIGIN")),
		// Go's default groups, hybrid first: what the reader's browser offers
		// decides, which is the whole point of asking.
		TLSConfig:         &tls.Config{MinVersion: tls.VersionTLS12, GetCertificate: kp.get},
		ReadHeaderTimeout: 5 * time.Second,
		ReadTimeout:       10 * time.Second,
		WriteTimeout:      10 * time.Second,
		IdleTimeout:       30 * time.Second,
		// The standard library's own errors (a scanner's broken handshake)
		// would otherwise name client addresses in the pod's log.
		ErrorLog: log.New(discard{}, "", 0),
	}
	log.Fatal(srv.ListenAndServeTLS("", ""))
}

type discard struct{}

func (discard) Write(p []byte) (int, error) { return len(p), nil }
