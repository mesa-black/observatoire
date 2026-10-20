package main

import (
	"crypto/tls"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
)

// The service's one promise: it names the group the client's own connection
// negotiated. A browser that offers the hybrid gets « hybrid »; one that offers
// only the classical exchange gets X25519, not a flattering default.
func TestItNamesTheGroupTheClientNegotiated(t *testing.T) {
	srv := httptest.NewUnstartedServer(report("https://observatoire.mesa.black"))
	srv.TLS = &tls.Config{MinVersion: tls.VersionTLS12}
	srv.StartTLS()
	defer srv.Close()

	for _, c := range []struct {
		curves []tls.CurveID
		group  string
		hybrid bool
	}{
		{[]tls.CurveID{tls.X25519MLKEM768, tls.X25519}, "X25519MLKEM768", true},
		{[]tls.CurveID{tls.X25519}, "X25519", false},
	} {
		client := srv.Client()
		tr := client.Transport.(*http.Transport).Clone()
		tr.TLSClientConfig.CurvePreferences = c.curves
		client.Transport = tr

		res, err := client.Get(srv.URL)
		if err != nil {
			t.Fatal(err)
		}
		var got struct {
			Group  string `json:"group"`
			Hybrid bool   `json:"hybrid"`
		}
		if err := json.NewDecoder(res.Body).Decode(&got); err != nil {
			t.Fatal(err)
		}
		res.Body.Close()
		if got.Group != c.group || got.Hybrid != c.hybrid {
			t.Errorf("offered %v: got %+v, want %s (hybrid %v)", c.curves, got, c.group, c.hybrid)
		}
		if res.Header.Get("Access-Control-Allow-Origin") != "https://observatoire.mesa.black" || res.Header.Get("Cache-Control") != "no-store" ||
			res.Header.Get("Strict-Transport-Security") == "" || res.Header.Get("X-Content-Type-Options") != "nosniff" {
			t.Errorf("headers: %v", res.Header)
		}
	}
}

func TestOnlyGet(t *testing.T) {
	srv := httptest.NewTLSServer(report(""))
	defer srv.Close()
	res, err := srv.Client().Post(srv.URL, "text/plain", nil)
	if err != nil {
		t.Fatal(err)
	}
	if res.StatusCode != http.StatusMethodNotAllowed {
		t.Errorf("POST: %d", res.StatusCode)
	}
}
